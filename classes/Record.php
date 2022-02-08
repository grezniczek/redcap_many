<?php namespace DE\RUB\MultipleExternalModule;

use \Exception;
use \REDCap;
use \Logging as REDCap_Logging;
use \UserRights as REDCap_UserRights;
use \Records as REDCap_Records;
use \ExternalModules\StatementResult;

// Note: SQL queries are hand-crafter, as the Framework prepared statements seem to
// not give the correct result, esp. when IS NULL is involved.
// Furthermore, we want complete statements for logging.
// All data in Record and Project are guaranteed to be safe.

class Record
{
    /** @var Project The project this record belongs to */
    private $project;
    /** @var string The id of this record */
    private $record_id;
    /** @var string The record id escaped for use in SQL queries */
    private $db_record;
    /** @var \ExternalModules\Framework The framework instance */
    private $framework;

    private const NON_REPEATING = 1;
    private const REPEAT_FORM = 2;
    private const REPEAT_EVENT = 3;

    function __construct($framework, $project, $record_id) {
        $this->project = $project;
        $this->record_id = $record_id;
        $this->db_record = db_escape($record_id);
        $this->framework = $framework;
    }

    #region -- Record -------------------------------------------------------------------------

    /**
     * Deletes the entire record.
     * @return int The number or recoords delete (1 or 0)
     * @throws Exception An exception is thrown in case of privileges violations
     */
    public function delete() {
        $this->project->requirePermission("record_delete");
        // Check that record (still) exists
        $recordIdField = $this->project->getRecordIdField();
        $multiple_arms = $this->project->multipleArms();
        $data = $this->getFieldValues($recordIdField, null);
        if (!empty($data[$recordIdField])) {
            $randomization = $this->project->withRandomization();
            $status = $this->project->getStatus();
            $requireReason = $this->project->requiresChangeReason();
            $arm_id = null;
            REDCap_Records::deleteRecord($this->record_id, $recordIdField, $multiple_arms,
                $randomization, $status, $requireReason, $arm_id, "Framework API");
            return 1;
        }
        return 0;
    }

    /**
     * Deletes the record in the specified arm only.
     * @param string|int $arm The unique arm name or the (numerical) arm id
     * @throws Exception An exception is thrown in case of privileges violations
     */
    public function deleteInArm($arm) {
        throw new Exception("Not implemented");
    }

    #endregion

    #region -- Form Instance Information ------------------------------------------------------

    /**
     * Gets the number of the form instances saved. Returns null if the form does not exist or is not repeating.
     * @param string $form The form name.
     * @param string|int $event The event name or event id.
     * @return null|int
     */
    public function getFormInstancesCount($form, $event) {
        if ($this->project->hasForm($form) && 
            $this->project->isFormRepeating($form, $event) &&
            $this->project->hasEvent($event)) {
            $event_id = $this->project->getEventId($event);
            $sql = "
                SELECT COUNT(*) as `count` 
                FROM redcap_data 
                WHERE `project_id` = ? AND 
                      `event_id` = ? AND 
                      `record` = ? AND 
                      `field_name` = ?";
            $result = $this->framework->query($sql, [
                $this->project->getProjectId(),
                $event_id,
                $this->record_id,
                $this->project->getFormStatusFieldNames($form)
            ]);
            $row = $result->fetch_assoc();
            return $row["count"];
        }
        else {
            return null;
        }

    }

    /**
     * Gets the last instance number of the form. Returns null if the form does not exist or is not repeating, and 0 if there are no instances saved yet.
     * @param string $form The form name.
     * @param string|int $event The event name or event id.
     * @return null|int
     */
    public function getFormLastInstanceNumber($form, $event) {
        if ($this->project->hasForm($form) && 
            $this->project->isFormRepeating($form, $event) &&
            $this->project->hasEvent($event)) {
            $event_id = $this->project->getEventId($event);
            $sql = "
                SELECT IF(`instance` IS NULL, 1, `instance`) AS instance 
                FROM redcap_data 
                WHERE `project_id` = ? AND 
                      `event_id` = ? AND 
                      `record` = ? AND 
                      `field_name` = ? 
                ORDER BY instance DESC 
                LIMIT 1";
            $result = $this->framework->query($sql, [
                $this->project->getProjectId(),
                $event_id,
                $this->record_id,
                $this->project->getFormStatusFieldNames($form)
            ]);
            $row = $result->fetch_assoc();
            return $row == null ? 0 : $row["instance"];
        }
        else {
            return null;
        }
    }

    /**
     * Gets the form status for the specified form(s) or all forms for the specified event. The return value is an associative array:
     * [ 
     *   form_name => [ instance => status, ... ]
     *   ...
     * ]
     * Status is null|0|1|2 (gray/unsaved, red/incomplete, yellow/unverified, green/complete)
     * Instance will be 1 for non-repeating forms/events.
     * 
     * @param array<string>|string|null $forms A form name (or list of form names; use NULL for all forms)
     * @param string|int|null $event The unique event name or (numerical) event id (can be omitted for non-longitudinal projects)
     * @return array<string,array<number,string>
     */
    public function getFormStatus($forms, $event = null) {
        // Input validation
        $event_id = $this->requireEventId($event);
        if ($forms === null) $forms = $this->project->getFormsByEvent($event_id);
        if (!is_array($forms)) $forms = array($forms);
        $forms = $this->requireFormEvent($forms, $event_id);
        // Prepare return value
        $forms_status = array();
        if ($this->project->isEventRepeating($event_id)) {
            // Add in a gray status for all forms - overwrite with actual status later.
            // This is necessary, as event instances can be all gray and would not be 
            // detected in the query.
            $event_instances = $this->getRepeatingEventInstances($event_id);
            // As there will ALWAYS be the first instance of a repeating event,
            // even when there is no data in the event, add instance 1 if it 
            // doesn't exist.
            if (!in_array(1, $event_instances, true)) {
                array_unshift($event_instances, 1);
            }
            foreach ($event_instances as $event_instance) {
                foreach ($forms as $form) {
                    $forms_status[$form][$event_instance] = null;
                }
            }
        }
        else {
            foreach ($forms as $form) {
                $forms_status[$form] = array( 1 => null );
            }
        }
        $q = $this->framework->createQuery();
        $q->add("SELECT `value`, `field_name`, `instance` FROM redcap_data WHERE 
            `project_id` = ? AND `record` = ? AND event_id = ? AND", [
            $this->project->getProjectId(), 
            $this->record_id, $event_id
        ]);
        $q->addInClause("`field_name`", $this->project->getFormStatusFieldNames($forms));
        $result = $this->toStatementResult($q->execute());
        while ($row = $result->fetch_assoc()) {
            $form = $this->project->getFormsFromStatusFieldNames($row["field_name"]);
            $instance = $row["instance"] === null ? 1 : $row["instance"] * 1;
            $value = ($row["value"] === "" || $row["value"] === null) ? null : $row["value"];
            $forms_status[$form][$instance] = $value;
        }
        return $forms_status;
    }

    #endregion


    #region -- Event Instance Information -----------------------------------------------------

    /**
     * Gets a list of all existing event instances of a repeating event
     * (i.e. those with data in at least one form).
     * @param string|int $event The unique event name or the (numerical event id).
     * @return array<int>
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function getRepeatingEventInstances($event) {
        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isEventRepeating($event_id)) {
            throw new Exception("Event {$event_id} is not repeating.");
        }
        // Query redcap_data for record_id to find events
        $instances = array();
        $q = $this->framework->createQuery();
        $q->add("SELECT IFNULL(`instance`, 1) AS `instance`
                 FROM redcap_data 
                 WHERE `project_id` = ? AND `event_id` = ? AND 
                       `record` = ? AND `field_name` = ?", [
            $this->project->getProjectId(),
            $event_id,
            $this->record_id,
            $this->project->getRecordIdField()
        ]);
        $result = self::toStatementResult($q->execute());
        while ($row = $result->fetch_assoc()) {
            array_push($instances, $row["instance"] * 1);
        }
        return $instances;
    }

    /**
     * Checks whether there is any data in the given event (instance).
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted for non-longitudinal projects)
     * @param array<int>|int|null $instances A list of instances or a single instance number (can be omitted for non-repeating events)
     * @return boolean
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function hasData($event = null, $instances = null) {


    }

    /**
     * Checks whether there is any data in the record outside the given event (instance).
     * @param string|int $event The unique event name or the (numerical) event id
     * @param int|null $instance The instance number (if no instance is specified for repeating events, all instances are excluded).
     * @return boolean
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function hasDataBesides($event, $instance = null) {
        $event_id = $this->requireEventId($event);
        $instance = $this->requireSingleInstance($instance);
        $instance = count($instance) ? $instance[0] : null;
        // Repeating? Longitudinal?
        $event_repeating = $this->project->isEventRepeating($event_id);
        $longitudinal = $this->project->isLongitudinal();
        // Cannot have data if not repeating AND not longitudinal
        if (!$longitudinal && !$event_repeating) return false;
        // Cannot have data if non-longidudinal with repating event, but all considered
        if (!$longitudinal && $event_repeating && $instance === null) return false;
        // Build queries
        $form_status_fields = $this->project->getFormStatusFieldNames();
        $q = $this->framework->createQuery();
        $q->add("SELECT 1 FROM redcap_data WHERE `project_id` = ? AND `record` = ?", [
            $this->project->getProjectId(),
            $this->record_id
        ]);
        if ($event_repeating && $instance !== null) {
            $instance = $instance == 1 ? null : $instance;
            $q->add("AND !(`event_id` = ? AND `instance` = ?)", [
                $event_id,
                $instance
            ]);
        }
        else {
            $q->add("AND !(`event_id` = ?)", [
                $event_id
            ]);
        }
        $q->add("AND");
        $q->addInClause("`field_name`", $form_status_fields);
        $q->add("LIMIT 1");
        $result = self::toStatementResult($q->execute());
        return $result->num_rows > 0;
    }

    #endregion


    #region -- Create Forms (Instances) -------------------------------------------------------

    /**
     * Adds (saves) new form instances.
     * 
     * Instance data must be supplied as an associative array, per instance, of the form
     * [
     *   [
     *     "field_1" => "value",
     *     "field_2" => "value",
     *     ...
     *   ]
     * ]
     * Data for a single instance must also be wrapped in an array.
     * 
     * @param string $form The form name (it must exist and be a repeating form).
     * @param string $event The event name or (numerical) event id.
     * @param array $instances An array of the instance data.
     * @return string A summary of the insertion: "event_id:first:last:count".
     * @throws Exception An exception is thrown in case of project data structure violations.
     */
    public function addFormInstances($form, $event, $instances) {
        // Check event.
        if (!$this->project->hasEvent($event)) {
            throw new \Exception("Event '{$event}' does not exist in project '{$this->project->getProjectId()}'.");
        }
        // Check form.
        if (!$this->project->hasForm($form) && !$this->project->isFormRepeating($form, $event)) {
            throw new \Exception("Form '{$form}' does not exist or is not repeating in event '{$event}'.");
        }
        // Check fields.
        foreach ($instances as $instance) {
            if (!is_array($instance)) {
                throw new \Exception("Invalid instance data format.");
            }
            foreach ($instance as $field => $value) {
                if (!(is_null($value) || is_string($value) || is_numeric($value) || is_bool($value))) {
                    throw new \Exception("Invalid value data type for field '$field'.");
                }
                if ($this->project->getFormByField($field) !== $form) {
                    throw new \Exception("Field '$field' is not on form '$form'.");
                }
            }
        }
        // Build data structure for REDCap::saveData().
        $event_id = $this->project->getEventId($event);
        $last_instance = $this->getFormLastInstanceNumber($form, $event);
        $first_instance = $last_instance + 1;
        $data = array();
        foreach ($instances as $instance_data) {
            $instance_data[$this->project->getFormStatusFieldNames($form)] = 2;
            $data[++$last_instance] = $instance_data;
        }
        $data = array(
            $this->record_id => array(
                "repeat_instances" => array(
                    $event_id => array (
                        $form => $data
                    )
                )
            )
        );

        // TODO: 
        // Permission issues? How to react? saveData does obey them, right?
        // Probably need to call Record::saveData???
        // Or enforce based on User Rights? Probably should check at least that by default.
        // The project permissions will allow modules to override

        REDCap::saveData(
            $this->project->getProjectId(), // project_id
            "array",                        // dataFormat
            $data,                          // data
            "overwrite"                     // overwriteBehavior
        );
        $count = $last_instance - $first_instance + 1;
        return "{$event_id}:{$first_instance}:{$last_instance}:{$count}";
    }

    #endregion


    #region -- Delete Forms (Instances) -------------------------------------------------------

    /**
     * Deletes repeating form instances.
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param array<int>|int $instances A list of instances or a single instance number
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function deleteFormInstances($form, $instances, $event = null) {
        // Check permission
        $this->project->requirePermission("record_delete");
        // Input validation

        // TODO

        // Assemble list of form/instances/event to delete
        $forms_to_delete = array();

        // TODO
        
        foreach ($forms_to_delete as $form_data) {
            $this->deleteForm($form_data["form"], $form_data["event_id"], $form_data["instance"]);
        }
    }

    /**
     * Deletes non-repeating forms on a (repeating) event.
     * 
     * @param array<string>|string|null $forms The unique form name(s). Forms must exist on the event and not be repeating. NULL will lock all forms on the event.
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param array<int>|int|null The (list of) event instance(s) or null (for non-repeating events or all events)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function deleteForms($forms, $event = null, $instances = null) {
        // Check permission
        $this->project->requirePermission("record_delete");
        // Input validation

        // TODO

        // Assemble list of form/instances/event to delete
        $forms_to_delete = array();

        // TODO
        
        foreach ($forms_to_delete as $form_data) {
            $this->deleteForm($form_data["form"], $form_data["event_id"], $form_data["instance"]);
        }
    }

    /**
     * Deletes a specific form (instance) on an event (instance).
     * 
     * @param string $form The unique form name. The form must exist on the event.
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param int|null The form or event instance (can be omitted or set to NULL|1 if the form/event is not repeating)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function deleteForm($form, $event = null, $instance = null) {
        // Check permission
        $this->project->requirePermission("record_delete");
        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormOnEvent($form, $event_id)) {
            throw new Exception("Form '{$form}' does not exist on event {$event_id}.");
        }
        $instances = $this->requireInstances($instance);
        // Repeating?
        $form_repeating = $this->project->isFormRepeating($form, $event_id);
        $event_repeating = $this->project->isEventRepeating($event_id);
        $repeating = $form_repeating || $event_repeating;
        if ($form_repeating && count($instances) != 1) {
            throw new Exception("Must specify an instance for repeating form '{$form}' on event {$event_id}.");
        }
        if ($event_repeating && count($instances) != 1) {
            throw new Exception("Must specify an instance for repeating event {$event_id}.");
        }
        if (!$repeating && count($instances) == 0) {
            $instances = array(1);
        }
        if (!$repeating && count($instances) != 1 && $instances[0] != 1) {
            throw new Exception("Invalid instance parameter for non-repeating form '{$form}' / event {$event_id} combination. Set to 1 or NULL.");
        }
        $instance = $instances[0];

        // Get form status - if gray, there is nothing to do
        $formsStatus = $this->getFormStatus(null, $event_id);
        if ($formsStatus[$form][$instance] === null) {
            return;
        }

        // Get list of all fields with data
        // Note - this will never include the record id field!
        $fields = $this->project->getFieldsByForm($form); 
        // Load dat - we need this in order to clean up stuff (such as deleting files)
        $data = $this->getFieldValues($fields, $event_id, $instances);

        // Perform deletion - there is a LOT to consider

        // Unlock the form (this breaks all e-sigs implicitly)
        // This might throw if the form is locked and the user does not have lock/unlock privileges
        try {
            if ($form_repeating) {
                $this->unlockFormInstances($form, $instances, $event_id);
            }
            else {
                $this->unlockForms($form, $event_id, $instances);
            }
        }
        catch (\Throwable $e) {
            throw new Exception("Cannot delete locked form '{$form}' on event {$event_id} - insufficient rights.");
        }

        // Are there any file upload or signature fields? 
        // If so, the corresponding files must be marked for deletion.
        $fileOrSigFields = $this->project->getFormFileUploadAndSignatureFields($form);
        // Delete any files
        if (count($fileOrSigFields)) {
            foreach ($fileOrSigFields as $field) {
                foreach ($data[$field] as $_ => $edoc_id) {
                    if (!empty($edoc_id)) {
                        $this->deleteFile($edoc_id);
                    }
                }
            }
        }

        // Delete all responses from data table for this form
        $sql = "DELETE FROM redcap_data 
                WHERE `project_id` = {$this->project->getProjectId()} AND 
                      `event_id` = {$event_id} AND 
                      `record` = '{$this->db_record}' AND 
                      `instance` ";
        $sql .= $instance == 1 ? "IS NULL" : "= {$instance}";
        $sql .= " AND `field_name` IN (" . prep_implode($fields) . ")";
        $result = db_query($sql);

        // Prepare logging information
        $log_sql = "-- Deleted by EM Framework API\n{$sql}"; 
        $log_desc = "Delete all record data for single form"; // DO NOT CHANGE - REDCap relies on this for data history!
        $log_reason = $form_repeating ? "[instance = {$instance}]" : ""; // REDCap Bug: Instance number is not logged for form deletions!
        // For logging, pretend fields were set to their empty default values
        $logging = array();
        foreach ($fields as $field) {
            if (!empty($data[$field][$instance])) {
                // Add default data values to logging field list
                if ($this->project->isFieldOfTypeCheckbox($field)) {
                    foreach (array_keys($this->project->getFieldEnum($field)) as $code) {
                        $logging[] = "$field($code) = unchecked";
                    }
                } 
                else {
                    $logging[] = "$field = ''";
                }
            }
        }
        $log_display = join(",\n", $logging);

        // There is more housekeeping to do still!
        if ($this->project->isLongitudinal()) {
            // Check if all forms on this event/instance have gray status icon 
            // (implying that we just deleted the only form with data for this event)
            // We already obtained the form statuses previously. First, mark the one just
            // delete as gray.
            $formsStatus[$form][$instance] = null;
            // Then check for the rest of the event (instance)
            $allFormsDeletedOnThisEvent = true;
            foreach ($formsStatus as $_ => $this_instances) {
                $allFormsDeletedOnThisEvent &= $this_instances[$instance] === null;
            }

            if ($allFormsDeletedOnThisEvent) {
                // Now check to see if other events/instances for this record have data
                $otherEventsHaveData = $this->hasDataBesides($event_id, $instance);
                if ($otherEventsHaveData) {
                    // Since other events have data for this record, we should go ahead and
                    // remove ALL data from this event (because we might have __GROUPID__ and 
                    // record ID field stored on backend for this event still)
                    $sql = "DELETE FROM redcap_data 
                            WHERE `project_id` = {$this->project->getProjectId()} AND 
                                  `record` = '{$this->db_record}' AND
                                  `event_id` = {$event_id} AND 
                                  `instance` ";
                    $sql .= $instance == 1 ? "IS NULL" : "= {$instance}";
                    $result = db_query($sql);
                    if ($result) {
                        $log_sql .= ";\n{$sql}";
                    }
                }
            }
        }

        // If this form is a survey, then set all survey response timestamps to NULL
        // (or delete row if a non-first repeating instance)
        if ($this->project->surveysEnabled() && $this->project->isSurvey($form)) {
            $survey_id = $this->project->getSurveyId($form);
            $sql = "UPDATE redcap_surveys_participants p, redcap_surveys_response r 
                    SET r.first_submit_time = NULL, r.comletion_time = NULL
                    WHERE r.participant_id = p.participant_id AND
                          p.survey_id = {$survey_id} AND
                          r.record = '{$this->db_record}' AND
                          p.event_id = {$event_id} AND 
                          r.instance = {$instance}";
            $result = db_query($sql);
            if ($result == true) {
                $log_sql .= ";\n{$sql}";
            }
            // For repeating instruments/events, remove this instance from participant list if instance > 1
            if ($repeating && $instance > 1) {
                $sql = "SELECT p.participant_id 
                        FROM redcap_surveys_participants p, redcap_surveys_response r
                        WHERE r.participant_id = p.participant_id AND
                              p.survey_id = {$survey_id} AND
                              r.record = '{$this->db_record}' AND
                              p.event_id = {$event_id} AND
                              r.instance = {$instance}
                        LIMIT 1";
                $result = db_query($sql);
                if (db_num_rows($result)) {
                    $participant_id = db_result($result, 0);
                    $sql = "DELETE FROM redcap_surveys_participants WHERE participant_id = {$participant_id}";
                    $result = db_query($sql);
                    if ($result === true) {
                        $log_sql .= ";\n{$sql}";
                    }
                }
            }
        }
        // Log the data change
        $log_sql = $this->project->oneLineSQL($log_sql);
        REDCap_Logging::logEvent($log_sql, "redcap_data", "UPDATE", $this->record_id,
            $log_display, $log_desc, $log_reason, $this->project->getPermissionsUser(), 
            $this->project->getProjectId(), $this->now(), $event_id, $instance);
    }

    /**
     * Deletes a file (marks it for deletion)
     * @param int $edoc_id The document ID
     * @return string The SQL query that was executed
     */
    public function deleteFile($edoc_id) {
        $edoc_id = $this->requireInt($edoc_id);
        $sql = "UPDATE redcap_edocs_metadata 
                SET `delete_date` = '{$this->now()}' 
                WHERE `doc_id` = {$edoc_id} AND 
                      `project_id` = {$this->project->getProjectId()}";
        $result = db_query($sql);
        return $sql;
    }

    #endregion


    #region -- Locking and E-Signature (Forms, Instances) -------------------------------------

    /**
     * Gets a list of locked non-repeating forms on a (repeating) event.
     * Data will be returned in the format:
     * [
     *   event_instance => [ form_name, form_name, ... ],
     *   ...
     * ]
     * In case of non-repating events, the event_instance will be 1.
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param array<int>|int|null The (list of) event instance(s) or null (for non-repeating events or all events)
     * @return array<int,array<string>>
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function getLockedForms($event = null, $instances = null) {
        // Input validation
        $event_id = $this->requireEventId($event);
        $event_repeating = $this->project->isEventRepeating($event_id);
        $instances = $this->requireInstances($instances);
        if (!$event_repeating && count($instances) > 1 && $instances[0] !== 1) {
            throw new Exception("Invalid instance parameter for non-repeating event {$event_id}.");
        }
        // Query database
        $q = $this->framework->createQuery();
        $q->add("SELECT `form_name`, `instance` FROM redcap_locking_data
                 WHERE `project_id` = ? AND `record` = ? AND `event_id` = ?", [
                     $this->project->getProjectId(), 
                     $this->record_id, $event_id
                 ]);
        if ($event_repeating && count($instances)) {
            $q->add("AND");
            $q->addInClause("`instance`", $instances);
        }
        $result = self::toStatementResult($q->execute());
        // Prepare return array
        $locked_instances = array();
        foreach ($instances as $instance) {
            $locked_instances[$instance] = array();
        }
        // Fill it with locked forms
        while ($row = $result->fetch_assoc()) {
            $instance = $row["instance"];
            $form = $row["form_name"];
            $locked_instances[$instance][] = $form;
        }
        return $locked_instances;
    }

    /**
     * Locks non-repeating forms on a (repeating) event.
     * 
     * @param array<string>|string|null $forms The unique form name(s). Forms must exist on the event and not be repeating. NULL will lock all forms on the event.
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param array<int>|int|null The (list of) event instance(s) or null (for non-repeating events or all events)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function lockForms($forms = null, $event = null, $instances = null) {
        // Check permission
        $this->project->requirePermission("lock_record");
        // Input validation
        $event_id = $this->requireEventId($event);
        $instances = $this->requireInstances($instances);
        if ($forms === null) {
            $forms = $this->project->getFormsByEvent($event_id);
        }
        else if (!is_array($forms)) {
            $forms = array($forms);
        }
        foreach ($forms as $form) {
            if (!$this->project->isFormOnEvent($form, $event_id)) {
                throw new Exception("Form '$form' is not on event $event_id.");
            }
        }
        $event_repeating = $this->project->isEventRepeating($event_id);
        if (!$event_repeating && count($instances) > 1 && $instances[0] !== 1) {
            throw new Exception("Invalid instance parameter for non-repeating event {$event_id}.");
        }
        // In case of repeating event and no instances given, get a list of all event instances with data
        if ($event_repeating && count($instances) == 0) {
            // Need to get all instances of the event with existing data
            $instances = $this->getRepeatingEventInstances($event_id);
        }
        else if ($event_repeating && count($instances)) {
            // Ensure that only exiting events are passed as parameters.
            // To be debated: Throw if mismatch, or just silently limit to existing?
            // For now, limit (as concurrent operations could have removed an instance).
            $existing_instances = $this->getRepeatingEventInstances($event_id);
            $instances = array_intersect($instances, $existing_instances);
        }
        else if (!$event_repeating && count($instances) == 0) {
            // Add default instance 1
            $instances = array( 1 );
        }
        else if (!$event_repeating && count($instances)) {
            // Ensure that for non-repeating events, if instances are passed as parameter,
            // this is equal to event 1 only.
            // In contrast to the situation above, we will throw here, as this is a 
            // definite error!
            if (count($instances) > 1 || $instances[0] !== 1) {
                throw new Exception("Invalid instances parameter passed for non-repeating event {$event_id}");
            }
        }

        // Now that validation is done, let's (finally) get to work on the acutal locking process.
        // First, get a list of already locked forms, as we do not want to add duplicate entries 
        // to the locking table.
        $locked_forms = $this->getLockedForms($event_id, $instances);
        // Then, also get a list of "gray" forms as these have to be excluded as well.
        $gray_forms = $this->getFormStatus($forms, $event_id);
        // Now, assemble a list of all the forms/event/instance combinations to lock, excluding
        // those already locked.
        $forms_to_lock = array();
        foreach ($forms as $form) {
            foreach($instances as $instance) {
                if (!in_array($form, $locked_forms[$instance]) &&
                    $gray_forms[$form][$instance] !== null) {
                    $lock = array(
                        "form" => $form,
                        "instance" => $instance
                    );
                    array_push($forms_to_lock, $lock);
                }
            }
        }
        // Update database
        $db_user = db_escape($this->userId());
        foreach ($forms_to_lock as $lock) {
            $sql = "INSERT INTO redcap_locking_data 
                            (`project_id`, `record`, `event_id`, `form_name`, `username`, `timestamp`, `instance`) 
                        VALUES ( 
                             {$this->project->getProjectId()}, 
                            '{$this->db_record}', 
                             {$event_id}, 
                            '{$lock["form"]}', 
                            '{$db_user}', 
                            '{$this->now()}', 
                             {$lock["instance"]})";
            try {
                $result = db_query($sql);
            }
            catch (\Throwable $e) {
                // Ok, now what? This could only mean a duplicate insert. Silently ignore. No harm.
                $result = false;
            }
            // Update log
            if ($result === true) {
                $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($lock["form"])}";
                if ($event_repeating) {
                    // Let's add instance here instead of as a separate parameter
                    $log_entry .= "\nInstance: {$lock["instance"]}";
                }
                if ($this->project->isLongitudinal()) {
                    $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
                }
                $log_sql = $this->project->oneLineSQL(
                    "-- Form locked by Framework API\n$sql");
                REDCap_Logging::logEvent($log_sql, "redcap_locking_data", "LOCK_RECORD", 
                    $this->record_id, $log_entry, "Lock instrument", "", $this->userId(), 
                    $this->project->getProjectId(), true, $event_id, null, false);
            }
        }
    }

    /**
     * Unlocks non-repeating forms on (repeating) events.
     * 
     * @param array<string>|string|null $forms The unique form name(s). Forms must exist on the event and not be repeating. NULL will lock all forms on the event.
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param array<int>|int|null The (list of) event instance(s) or null (for non-repeating events or all events)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function unlockForms($forms = null, $event = null, $instances = null) {
        // Input validation
        $event_id = $this->requireEventId($event);
        $instances = $this->requireInstances($instances);
        if ($forms === null) {
            $forms = $this->project->getFormsByEvent($event_id);
        }
        else if (!is_array($forms)) {
            $forms = array($forms);
        }
        foreach ($forms as $form) {
            if (!$this->project->isFormOnEvent($form, $event_id)) {
                throw new Exception("Form '$form' is not on event $event_id.");
            }
        }
        $event_repeating = $this->project->isEventRepeating($event_id);
        if (!$event_repeating && count($instances) > 1 && $instances[0] !== 1) {
            throw new Exception("Invalid instance parameter for non-repeating event {$event_id}.");
        }
        // In case of repeating event and no instances given, get a list of all event instances with data
        if ($event_repeating && count($instances) == 0) {
            // Need to get all instances of the event with existing data
            $instances = $this->getRepeatingEventInstances($event_id);
        }
        else if ($event_repeating && count($instances)) {
            // Ensure that only exiting events are passed as parameters.
            // To be debated: Throw if mismatch, or just silently limit to existing?
            // For now, limit (as concurrent operations could have removed an instance).
            $existing_instances = $this->getRepeatingEventInstances($event_id);
            $instances = array_intersect($instances, $existing_instances);
        }
        else if (!$event_repeating && count($instances) == 0) {
            // Add default instance 1
            $instances = array( 1 );
        }
        else if (!$event_repeating && count($instances)) {
            // Ensure that for non-repeating events, if instances are passed as parameter,
            // this is equal to event 1 only.
            // In contrast to the situation above, we will throw here, as this is a 
            // definite error!
            if (count($instances) > 1 || $instances[0] !== 1) {
                throw new Exception("Invalid instances parameter passed for non-repeating event {$event_id}");
            }
        }

        // Now that validation is done, let's (finally) get to work on the acutal unlocking process.
        // First, get a list of locked forms, as we only need to unlock those.
        // We could simple call DELETE on non-locked forms, but what about logging?
        $locked_forms = $this->getLockedForms($event_id, $instances);
        // Now, assemble a list of all the forms/event/instance combinations to unlock, and take
        // only those that are actually locked.
        $forms_to_unlock = array();
        foreach ($forms as $form) {
            foreach($instances as $instance) {
                if (in_array($form, $locked_forms[$instance])) {
                    $unlock = array(
                        "form" => $form,
                        "instance" => $instance
                    );
                    array_push($forms_to_unlock, $unlock);
                }
            }
        }
        // Anything to do?
        if (!count($forms_to_unlock)) return;

        // Check permission to unlock - we do this late when we know there is actually anything to do
        $this->project->requirePermission("lock_record");

        // Unlock
        foreach ($forms_to_unlock as $unlock) {
            $db_form = db_escape($unlock["form"]);
            $sql = "DELETE FROM redcap_locking_data WHERE 
                `project_id` = {$this->project->getProjectId()} AND 
                `record` = '{$this->db_record}' AND 
                `event_id` = {$event_id} AND
                `form_name` = '{$db_form}' AND 
                `instance` = {$unlock["instance"]}";
            $result = db_query($sql);
            if ($result === true) {
                // Is the form e-signed? If so, negate the e-signature
                if ($this->isFormESigned($unlock["form"], $event_id, $instance)) {
                    // It is probably not necessary to check first, but instead simply negate
                    $this->negateFormESignature($form, $event_id, $unlock["instance"]);
                }
                // Update log
                $form_display_name = $this->project->getFormDisplayName($unlock["form"]);
                $log_entry = "Record: {$this->record_id}\nForm: {$form_display_name}";
                if ($event_repeating) {
                    // Let's add instance here instead of as a separate parameter
                    $log_entry .= "\nInstance: {$unlock["instance"]}";
                }
                if ($this->project->isLongitudinal()) {
                    $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
                }
                $log_sql = $this->project->oneLineSQL(
                    "-- Form unlocked by Framework API\n$sql");
                REDCap_Logging::logEvent($log_sql, "redcap_locking_data", "LOCK_RECORD", 
                    $this->record_id, $log_entry, "Unlock instrument", "", $this->userId(), 
                    $this->project->getProjectId(), true, $event_id, null, false);
            }
        }
    }

    /**
     * Gets a list of locked instances of a form.
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @return array<int>
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function getLockedFormInstances($form, $event = null) {
        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        // Query database
        $sql = "SELECT `instance` FROM redcap_locking_data
                WHERE `project_id` = ? AND
                      `record` = ? AND
                      `event_id` = ? AND
                      `form_name` = ?";
        $result = $this->framework->query($sql, array(
            $this->project->getProjectId(),
            $this->record_id,
            $event_id,
            $form
        ));
        $locked_instances = array();
        while ($row = $result->fetch_assoc()) {
            array_push($locked_instances, $row["instance"] * 1);
        }
        return $locked_instances;
    }

    /**
     * Locks repeating form instances.
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param array<int>|int $instances A list of instances or a single instance number
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @throws Exception An exception is thrown in case of project data structure or privileges violations
     */
    public function lockFormInstances($form, $instances, $event = null) {
        // Check permission
        $this->project->requirePermission("lock_record");

        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        $instances = $this->requireInstances($instances);
        if (!count($instances)) return; // Nothing to do
        // Get a list of already locked instances
        $locked_instances = $this->getLockedFormInstances($form, $event_id);
        // Determine those to lock
        $instances_to_lock = array_diff($instances, $locked_instances);
        if (!count($instances_to_lock)) return; // Nothing to do

        // Lock instances
        $db_form = db_escape($form);
        $db_user = db_escape($this->userId());
        $lock_success = array();
        $lock_fail = array();
        $log_values = array();
        $sql = "INSERT INTO redcap_locking_data 
                  (`project_id`, `record`, `event_id`, `form_name`, `username`, `timestamp`, `instance`) 
                VALUES ";
        foreach($instances_to_lock as $instance) {
            $values = "( 
                 {$this->project->getProjectId()}, 
                '{$this->db_record}', 
                 {$event_id}, 
                '{$db_form}', 
                '{$db_user}', 
                '{$this->now()}', 
                 {$instance}
            )";
            $result = db_query($sql . $values);
            if ($result === true) {
                $lock_success[] = $instance;
                $log_values[] = $values;
            } 
            else {
                $lock_fail[] = $instance;
            }
        }
        // Update log (bulk)
        if (count($lock_success)) {
            $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
            if ($this->project->isLongitudinal()) {
                $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
            }
            $log_entry = str_replace("#INST#", join(",", $lock_success), $log_entry);
            $log_sql = "-- Form instances locked by Framework API\n$sql" . 
                join(", ", $log_values);
            $log_sql = $this->project->oneLineSQL($log_sql);
            REDCap_Logging::logEvent($log_sql, "redcap_locking_data", "LOCK_RECORD", 
                $this->record_id, $log_entry, "Lock instrument", "", $this->userId(), 
                $this->project->getProjectId(), true, $event_id, null, true);
        }
    }

    /**
     * Unlocks repeating form instances.
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param array|int $instances A list of instance numbers or a single instance number
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function unlockFormInstances($form, $instances, $event = null) {
        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        $instances = $this->requireInstances($instances);
        if (!count($instances)) return; // Nothing to do
        // Get a list of already locked instances
        $locked_instances = $this->getLockedFormInstances($form, $event_id);
        // Determine those to lock
        $instances_to_unlock = array_intersect($instances, $locked_instances);
        if (!count($instances_to_unlock)) return; // Nothing to do

        // Check permission - we do this late, when we know there is actually something to unlock
        $this->project->requirePermission("lock_record");

        // Unlock instances
        $unlock_success = array();
        $unlock_fail = array();
        $db_form = db_escape($form);
        $log_sql = array();
        foreach ($instances_to_unlock as $instance) {
            $sql = "DELETE FROM redcap_locking_data WHERE 
                `project_id` = {$this->project->getProjectId()} AND 
                `record` = '{$this->db_record}' AND 
                `event_id` = {$event_id} AND
                `form_name` = '{$db_form}' AND 
                `instance` = {$instance}";
            $result = db_query($sql);
            if ($result === true) {
                $log_sql[] = $sql;
                // Is the form e-signed? If so, negate the e-signature
                if ($this->isFormInstanceESigned($form, $instance, $event_id)) {
                    // It is probably not necessary to check first, but instead simply negate
                    $this->negateFormInstanceESignature($form, $instance, $event_id);
                }
                $unlock_success[] = $instance;
            }
            else {
                $unlock_fail[] = $instance;
            }
        }
        // Update log (bulk)
        if (count($unlock_success)) {
            $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
            if ($this->project->isLongitudinal()) {
                $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
            }
            $log_entry= str_replace("#INST#", join(", ", $unlock_success), $log_entry);
            $log_sql = "-- Form instances unlocked by Framework API\n" . join(";\n", $log_sql);
            $log_sql = $this->project->oneLineSQL($log_sql);
            REDCap_Logging::logEvent($log_sql, "redcap_locking_data", "LOCK_RECORD", 
                $this->record_id, $log_entry, "Unlock instrument", "", $this->userId(), 
                $this->project->getProjectId(), true, $event_id, null, true);
        }
    }

    /**
     * Negates a form's e-signature on an event (instance).
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @param int|null $instance An event instance (in case of repeating events; in case of a non-repeating event, this should be omitted or set to 1 or NULL)
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function negateFormESignature($form, $event = null, $instance = null) {
        // Validate input
        $event_id = $this->requireEventId($event);
        $form = $this->requireFormEvent($form, $event_id);
        $instance = $this->requireInstances($instance);
        $event_repeating = $this->project->isEventRepeating($event_id);
        if ($event_repeating && count($instance) != 1) {
            throw new \Exception("A single valid instance must be supplied when the event is repeating.");
        }
        if (!$event_repeating && count($instance) == 0) {
            $instance = array( 1 );
        }
        if (!$event_repeating && count($instance) != 1 && $instance[0] != 1) {
            throw new Exception("Only instance 1 (or null) is allowed for non-repeating events.");
        }

        // Delete from table
        // No need to check if table row exists
        $db_form = db_escape($form);
        $sql = "DELETE FROM redcap_esignatures WHERE 
            `project_id` = {$this->project->getProjectId()} AND 
            `record` = '{$this->db_record}' AND 
            `event_id` = {$event_id} AND 
            `form_name` = '{$db_form}' AND 
            `instance` = {$instance[0]}";
        $result = db_query($sql);
        if ($result === true) {
            // Update log
            $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}";
            if ($this->project->isLongitudinal()) {
                $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
            }
            if ($event_repeating) {
                $log_entry .= "\nInstance: {$instance[0]}";
            }
            $log_sql = $this->project->oneLineSQL(
                "-- E-Signature(s) negated by Framework API\n$sql");
            REDCap_Logging::logEvent($log_sql, "redcap_esignatures", "ESIGNATURE", 
                $this->record_id, $log_entry, "Negate e-signature", "", $this->userId(), 
                $this->project->getProjectId(), true, $event_id, null, false);
        }
    }


    /**
     * Negates an e-signature on a form instance.
     * 
     * @param string $form The unique form name (it must exist and be a repeating form)
     * @param array|int $instances A list of instance numbers or a single instance number
     * @param string|int $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function negateFormInstanceESignature($form, $instances, $event = null) {
        // Validate input
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        $instances = $this->requireInstances($instances);
        $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
        if ($this->project->isLongitudinal()) {
            $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
        }
        $log_sql = array ();
        $db_form = db_escape($form);
        foreach ($instances as $instance) {
            // No need to check if table row exists
            $sql = "DELETE FROM redcap_esignatures WHERE 
                `project_id` = {$this->project->getProjectId()} AND 
                `record` = '{$this->db_record}' AND 
                `event_id` = {$event_id} AND
                `form_name` = '{$db_form}' AND 
                `instance` = {$instance}";
            $result = db_query($sql);
            if ($result === true) {
                $log_sql[] = $sql;
            }
        }
        if (count($log_sql)) {
            // Update log
            $log_sql = $this->project->oneLineSQL(
                "-- Form instance e-signature negated by Framework API\n" .
                join(";\n", $log_sql)
            );
            $log_entry = str_replace("#INST#", $instance, $log_entry);
            REDCap_Logging::logEvent($log_sql, "redcap_esignatures", "ESIGNATURE", 
                $this->record_id, $log_entry, "Negate e-signature", "", $this->userId(), 
                $this->project->getProjectId(), true, $event_id, null, true);
        }
        else {
            $log_sql = "";
        }
        return $log_sql;
    }


    /**
     * Checks whether the given form instance/s is/are e-signed.
     * When instances are supplied as an array, an array with the subset of signed instances is returned. 
     * @param string $form The unique form name
     * @param array<int>|int $instances
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projets)
     * @return bool|array<int>
     */
    public function isFormInstanceESigned($form, $instances, $event = null) {
        // Validate input
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        $return_as_array = is_array($instances);
        $instances = $this->requireInstances($instances);
        $count = count($instances);
        if ($count == 0) return false; // Nothing to do
        
        // Different strategy depending on number of instances requested
        if ($count == 1) {
            $sql = "SELECT 1 FROM redcap_esignatures 
                    WHERE `project_id` = ? AND 
                          `record` = ? AND 
                          `event_id` = ? AND 
                          `form_name` = ? AND 
                          `instance` = ? 
                    LIMIT 1";
            $result = $this->framework->query($sql, [
                $this->project->getProjectId(),
                $this->record_id,
                $event_id,
                $form,
                $instances[0]
            ]);
            if ($result->num_rows == 1) {
                return $return_as_array ? $instances : true;
            }
            else {
                return $return_as_array ? array() : false;
            }
        }
        else {
            // Get all e-signed and compare lists
            $esigned_instances = $this->getESignedFormInstances($form, $event_id);
            return array_intersect($instances, $esigned_instances);
        }
    }

    /**
     * Gets a list of form instances that are e-signed.
     * 
     * @param string $form The unique form name
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projets)
     * @return array<int>
     */
    public function getESignedFormInstances($form, $event = null) {
        // Validate input
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormRepeating($this->requireFormEvent($form, $event_id), $event_id)) {
            throw new Exception("Form '{$form}' is not repeating on event '{$event_id}'.");
        }
        // Query database
        $sql = "SELECT `instance` FROM redcap_esignatures 
                WHERE `project_id` = ? AND 
                      `record` = ? AND 
                      `event_id` = ? AND 
                      `form_name` = ?";
        $result = $this->framework->query($sql, [
            $this->project->getProjectId(),
            $this->record_id,
            $event_id,
            $form
        ]);
        $esigned_instances = array();
        while ($row = $result->fetch_assoc()) {
            array_push($esigned_instances, $row["instance"] * 1);
        }
        return $esigned_instances;
    }


    /**
     * Checks whether the given form on the event (instance) is e-signed.
     * An event instance must be supplied when the form is on a repeating event. 
     * @param string $form The unique form name
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projets)
     * @param int|null $instance The event instance (can be omitted or set to null if the event is not repeating)
     * @return bool
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function isFormESigned($form, $event = null, $instance = null) {
        // Validate input
        $event_id = $this->requireEventId($event);
        $form = $this->requireFormEvent($form, $event_id);
        $instance = $this->requireInstances($instance);
        $event_repeating = $this->project->isEventRepeating($event_id);
        if ($event_repeating && count($instance) != 1) {
            throw new \Exception("A single valid instance must be supplied when the event is repeating.");
        }
        if (!$event_repeating && count($instance) == 0) {
            $instance = array( 1 );
        }
        if (!$event_repeating && count($instance) != 1 && $instance[0] != 1) {
            throw new Exception("Only instance 1 (or null) is allowed for non-repeating events.");
        }
        
        // Query database
        $q = $this->framework->createQuery();
        $q->add("SELECT 1 FROM redcap_esignatures 
                 WHERE `project_id` = ? AND `record` = ? AND `event_id` = ? AND 
                       `form_name` = ? AND `instance` = ? LIMIT 1", [
            $this->project->getProjectId(),
            $this->record_id,
            $event_id,
            $form,
            $instance[0]
        ]);
        $result = self::toStatementResult($q->execute());
        return $result->num_rows == 1;
    }

    #endregion


    #region -- Get/Set Field Values -----------------------------------------------------------

    /**
     * Updates fields. 
     * The fields must all be on the same event and if repeating, 
     * on the same form (unless the event itself is repeating).
     * 
     * @param array $field_values An associative array (field_name => value).
     * @param string $event The name of the event or the (numerical) event id.
     * @param int $instances The repeat instance (optional).
     * @throws Exception for violations of the project data structure.
     */
    function updateFields($field_values, $event, $instances = 1) {
        // Validate input.
        if (!is_array($instances)) $instances = array($instances);
        $fields = array_keys($field_values);
        $mode = $this->validateFields($fields, $event, $instances);
        if ($mode == null) return;
        // Verify record / instance exists.
        $event_id = $this->project->getEventId($event);
        $project_id = $this->project->getProjectId();
        $form = $this->project->getFormByField($fields[0]);
        $sql = "SELECT COUNT(*) AS `count`
                FROM redcap_data
                WHERE `project_id` = ? AND
                      `event_id`= ? AND
                      `record` = ? AND ";
        $parameters = array(
            $project_id, 
            $event_id, 
            $this->record_id
        );
        if ($mode == self::REPEAT_EVENT) {
            // Repeating event.
            $sql .= "`field_name` = ? AND (";
            array_push($parameters, $this->project->getRecordIdField());
            if (in_array(1, $instances)) {
                $sql .= "`instance` IS NULL";
                if (count($instances) > 1) {
                    $ps = join(", ", explode("", str_repeat("?", count($instances) - 1)));
                    $sql .= " OR `instance` IN ($ps)";
                    foreach ($instances as $instance) {
                        if ($instance == 1) continue;
                        array_push($parameters, $instance);
                    }
                }
            }
            else {
                $ps = join(", ", explode("", str_repeat("?", count($instances) - 1)));
                $sql .= "`instance` IN ($ps)";
                foreach ($instances as $instance) {
                    array_push($parameters, $instance);
                }
            }
            $sql .= ")";
        }
        else if ($mode == self::REPEAT_FORM) {
            // Repeating form.
            $sql .= "`field_name` = ? AND (";
            array_push($parameters, $this->project->getFormStatusFieldNames($form));
            if (in_array(1, $instances)) {
                $sql .= "`instance` IS NULL";
                if (count($instances) > 1) {
                    $ps = join(", ", explode("", str_repeat("?", count($instances) - 1)));
                    $sql .= " OR `instance` IN ($ps)";
                    foreach ($instances as $instance) {
                        if ($instance == 1) continue;
                        array_push($parameters, $instance);
                    }
                }
            }
            else {
                $ps = join(", ", explode("", str_repeat("?", count($instances) - 1)));
                $sql .= "`instance` IN ($ps)";
                foreach ($instances as $instance) {
                    array_push($parameters, $instance);
                }
            }
            $sql .= ")";
        }
        else {
            // Plain. It's enough that record exists.
            $sql .= "`field_name` = ? AND `instance` is null";
            array_push($parameters, $this->project->getRecordIdField());
        }
        $result = $this->framework->query($sql, $parameters);
        $row = $result->fetch_assoc();
        if ($row == null || $row["count"] == 0) {
            throw new \Exception("Cannot update as record, event, or instance(s) have no data yet.");
        }

        // Build data structure for REDCap::saveData().
        $data = null;
        if ($mode == self::REPEAT_EVENT) {
            $data = array(
                $this->record_id => array(
                    "repeat_instances" => array(
                        $event_id => array(
                            null => array(
                                $instance => $field_values
                            )
                        )
                    )
                )
            );
        }
        else if ($mode == self::REPEAT_FORM) {
            $data = array(
                $this->record_id => array(
                    "repeat_instances" => array(
                        $event_id => array(
                            $form => array(
                                $instance => $field_values
                            )
                        )
                    )
                )
            );
        }
        else {
            $data = array(
                $this->record_id => array(
                    $event_id => $field_values
                )
            );
        }
        $result = REDCap::saveData(
            $project_id, // project_id
            "array",     // dataFormat
            $data,       // data
            "overwrite"  // overwriteBehavior
        );
    }

    /**
     * Gets field values for the specified event and repeat instance.
     * The fields must all be on the same event and if repeating, 
     * on the same form (unless the event itself is repeating).
     * 
     * @param array<string>|string $fields An array of field names or a single field name
     * @param string $event The name of the event or the (numerical) event id.
     * @param int|array $instances The repeat instance(s) (optional).
     * @return array An associative array (field_name => value).
     * @throws Exception for violations of the project data structure.
     */
    public function getFieldValues($fields, $event, $instances = 1) {
        // Validate input.
        if (!is_array($instances)) $instances = array($instances);
        if (!is_array($fields)) $fields = array($fields);
        $mode = $this->validateFields($fields, $event, $instances);
        if ($mode == null) return array();
        
        $project_id = $this->project->getProjectId();
        $event_id = $this->project->getEventId($event);
        $form = $this->project->getFormByField($fields[0]);

        $data = REDCap::getData(
            $project_id,       // project_id
            "array",           // return_format
            $this->record_id,  // records
            $fields,           // fields
            $event_id          // events
        );
        $rv = array();
        foreach ($fields as $field) {
            $rv[$field] = array();
            foreach($instances as $instance) {
                if ($mode == self::REPEAT_EVENT) {
                    $rv[$field][$instance] = $data[$this->record_id]["repeat_instances"][$event_id][null][$instance][$field];
                }
                else if ($mode == self::REPEAT_FORM) {
                    $rv[$field][$instance] = $data[$this->record_id]["repeat_instances"][$event_id][$form][$instance][$field];
                }
                else {
                    $rv[$field][$instance] = $data[$this->record_id][$event_id][$field];
                }
            }
        }
        return $rv;
    }

    #endregion


    #region -- Private Helpers ----------------------------------------------------------------

    /**
     * Returns a query result as a StatementResult (for better IDE support).
     * @param mixed $result
     * @return StatementResult
     */
    private static function toStatementResult($result) {
        return $result;
    }

    /**
     * Gets the current date and time (Y-m-d H:i:s).
     * @return string
     */
    private function now() {
        return empty(NOW) ? date("Y-m-d H:i:s") : NOW;
    }

    /**
     * Gets the user set in the parent project, or the current user.
     * In case no user is set, "<UNKOWNN>" is returned.
     * @return string
     */
    private function userId() {
        $user_id = $this->project->getPermissionsUser();
        if (empty($user_id)) $user_id = USERID;
        return empty($user_id) ? "<UNKNOWN>" : $user_id;
    }

    /**
     * Ensures that val is an integer that is greater than or equal to min (defaults to 1).
     * @param mixed $val
     * @param int $min
     * @return int
     * @throws Exception $val is not an int
     */
    private function requireInt($val, $min = 1) {
        if (is_numeric($val) && is_int($val * 1) && ($val * 1) >= $min) {
            return $val * 1;
        }
        throw new Exception("'$val' does not fulfill the requirement 'integer >= $min'.");
    }

    /**
     * Requires a valid event.
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @return int
     * @throws Exception in case of invalid event
     */
    private function requireEventId($event) {
        $event_id = $this->project->getEventId($event);
        if ($event_id === null) {
            throw new \Exception("Invalid '{$event}' for project '{$this->project->getProjectId()}'.");
        }
        return $event_id;
    }

    /**
     * Requires a from (or several forms) to be on the given event.
     * @param string|array<string> $forms The unique instrument name(s)
     * @param int $event_id The (numerical) event id
     * @return string|array<string> The unique instrument name(s)
     */
    private function requireFormEvent($forms, $event_id) {
        $array = is_array($forms);
        if (!$array) $forms = array($forms);
        foreach ($forms as $form) {
            if (!$this->project->isFormOnEvent($form, $event_id)) {
                throw new Exception("Form '{$form}' is not on event '{$event_id}'.");
            }
        }
        return $array ? $forms : $forms[0];
    }

    /**
     * Validates the 'instances' parameter. It can be an (empty) array of ints or null.
     * @param array<int>|int|null $instances
     * @return array The instances
     */
    private function requireInstances($instances) {
        if (is_int($instances)) {
            $instances = array($instances);
        }
        else if ($instances === null) {
            $instances = array();
        }
        if (!is_array($instances)) {
            throw new Exception("Invalid instances parameter '{$instances}'.");
        }
        foreach ($instances as $instance) {
            if (!is_integer($instance) || $instances < 1) {
                throw new Exception("Invalid instance '{$instance}'. Must be an integer > 0.");
            }
        }
        return $instances;
    }

    /**
     * Validates the 'instances' parameter to represent single instance (or none).
     * @param int|null $instance
     * @return array<int> The instance
     */
    private function requireSingleInstance($instances) {
        $instances = $this->requireInstances($instances);
        if (count($instances) > 1) {
            throw new Exception("Must not specify more than one instance.");
        }
        return $instances;
    }

    /**
     * Validates compatibility of "fields, event, instance" combinations with project data structure.
     * 
     * @param array $fields A list of field names.
     * @param string $event The event name or (numerical) event id.
     * @param array<int> $instances The repeat instance (optional).
     * @return int|null The mode - one of REPEAT_EVENT, REPEAT_FORM, NON_REPEATING, or null if there is nothing to do.
     * @throws Excetion in case of violations.
     */
    private function validateFields($fields, $event, $instances) {
        $mode = null;
        // Anything to do?
        if (!count($fields)) return $mode;
        // Check instance.
        $max_instance = 0;
        $min_instance = 99999999;
        foreach ($instances as $instance) {
            if (!is_int($instance) || $instance < 1) {
                throw new Exception("Instances must be integers > 0.");
            }
            $max_instance = max($max_instance, $instance);
            $min_instance = min($min_instance, $instance);
        }
        if (count($instances) && $max_instance == 0) {
            throw new Exception("Invalid instances.");
        }
        // Check event.
        $event_id = $this->project->getEventId($event);
        $project_id = $this->project->getProjectId();
        if ($event_id === null) {
            throw new Exception("Event '{$event}' does not exist in project '{$project_id}'.");
        }
        if($this->project->isEventRepeating($event)) {
            // All fields on this event?
            foreach ($fields as $field) {
                if (!$this->project->isFieldOnEvent($field, $event)) {
                    throw new Exception("Field '{$field}' is not on event '{$event}'.");
                }
            }
            $mode = self::REPEAT_EVENT;
        }
        else {
            // Are all fields on the same form?
            $form = $this->project->areFieldsOnSameForm($fields);
            // And if so, is it repeating?
            if ($form && $max_instance > 1 && !$this->project->isFormRepeating($form, $event)) {
                throw new Exception("Invalid instance(s). Fields are on form '{$form}' which is not repeating on event '{$event}.");
            }
            if (!$form) {
                // Fields are on more than one form. None of the fields must be on a repeating form.
                foreach ($fields as $field) {
                    if ($this->project->isFieldOnRepeatingForm($field, $event)) {
                        throw new Exception("Must not mix fields that are on non-repeating and repeating forms.");
                    }
                }
            }
            $mode = $form && $this->project->isFormRepeating($form, $event) ? self::REPEAT_FORM : self::NON_REPEATING;
        }
        return $mode;
    }

    #endregion

}
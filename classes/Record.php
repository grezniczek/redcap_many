<?php namespace DE\RUB\MultipleExternalModule;

use Exception;
use \REDCap;
use \Logging as REDCap_Logging;
use \UserRights as REDCap_UserRights;
use \ExternalModules\StatementResult;

class Record
{
    /** @var Project The project this record belongs to. */
    private $project;
    /** @var string The id of this record. */
    private $record_id;
    /** @var \ExternalModules\Framework The framework instance. */
    private $framework;

    private const NON_REPEATING = 1;
    private const REPEAT_FORM = 2;
    private const REPEAT_EVENT = 3;

    function __construct($framework, $project, $record_id) {
        $this->project = $project;
        $this->record_id = $record_id;
        $this->framework = $framework;
    }


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
                "{$form}_complete"
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
                "{$form}_complete"
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
        foreach ($forms as $form) {
            $forms_status[$form] = array();
        }
        $q = $this->framework->createQuery();
        $q->add("SELECT `value`, `field_name`, `instance` FROM redcap_data WHERE `project_id` = ? AND `record` = ? AND event_id = ? AND", [
            $this->project->getProjectId(), $this->record_id, $event_id
        ]);
        $add_complete = function($form_name) { 
            return $form_name . "_complete"; 
        };
        $q->addInClause("`field_name`", array_map($add_complete, $forms));
        $sql = $q->getSQL();
        $result = $this->toStatementResult($q->execute());
        while ($row = $result->fetch_assoc()) {
            $form = substr($row["field_name"], 0, strlen($row["field_name"]) - 9); // Remove "_complete" from end only!
            $instance = $row["instance"] === null ? 1 : $row["instance"] * 1;
            $value = ($row["value"] === "" || $row["value"] === null) ? null : $row["value"];
            $forms_status[$form][$instance] = $value;
        }
        // Add in repeating status (first instance = null if none are present)
        foreach ($forms_status as $form => &$instance_status) {
            if (empty($instance_status)) {
                $instance_status = array( 1 => null );
            }
        }
        return $forms_status;
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
            $instance_data["{$form}_complete"] = 2;
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
     * Deletes all instances of data on a single form.
     * If anything other than NULL is passed for instances, the form must be repeating on the 
     * given event. If NULL is passed for instancens, the form must not be repeating.     * 
     * @param string $form The unique form name (this must be a repeating form unless null is passed for instances)
     * @param array<int>|int|null $instances The instances, a single instance, or null
     * @param string|int|null $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @throws Exception An exception is thrown in case of project data structure violations
     */
    public function deleteFormInstances($form, $instances, $event = null) {
        // Check permission
        $this->project->requirePermission("record_delete");
        // Input validation
        $event_id = $this->requireEventId($event);
        if (!$this->project->isFormOnEvent($form, $event_id)) {
            throw new Exception("Form '{$form}' does not exist on event {$event_id}.");
        }
        // Repeating?
        $form_repeating = $this->project->isFormRepeating($form, $event_id);
        if ($form_repeating && $instances === null) {
            throw new Exception("Must specify instances for repeating form '{$form}' on event {$event_id}.");
        }
        $event_repeating = $this->project->isEventRepeating($event_id);
        if ($event_repeating && $instances === null) {
            throw new Exception("Must specify instances for repeating event {$event_id}.");
        }
        $repeating = $form_repeating || $event_repeating;
        if (!$repeating && $instances !== null) {
            throw new Exception("Form {'$form'} must be repeating on event {$event_id} or the event must be repeating when instances is not NULL.");
        }
        // Check instances, if none
        $instances = $this->requireInstances($instances);
        // Add instance 1 for non-repeating
        if (!$repeating && count($instances) == 0) $instances = array ( 1 );

        // Prepare some data
        $project_id = $this->project->getProjectId();
        $now = empty(NOW) ? date("Y-m-d H:i:s") : NOW;
        
        // Perform deletion - there is a LOT to consider
        // Get list of all fields with data
        $fields = $this->project->getFieldsByForm($form); // Note - this will never include the record id field!
        $data = $this->getFieldValues($fields, $event_id, $instances);
        // Are there any file upload or signature fields? If so, the corresponding files must be marked for deletion.
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
        // Loop through all instances, delete data, and log (setting fields with data to their empty default values)
        foreach ($instances as $instance) {
            // Delete all responses from data table for this form
            $deleteQuery = $this->framework->createQuery();
            $deleteQuery->add("DELETE FROM redcap_data WHERE `project_id` = ? AND `event_id` = ? AND `record` = ? AND", [
                $project_id, $event_id, $this->record_id
            ]);
            $deleteQuery->addInClause("`field_name`", $fields);
            if ($repeating) {
                $deleteQuery->add("AND `instance` = ?", [$instance]);
            }
//            $result = self::toStatementResult($deleteQuery->execute());
            $deleteSql = $deleteQuery->getSQL();
            
            $formsStatus = $this->getFormStatus($form, $event_id);





            // Logging
            $logging = array();
            foreach ($fields as $field) {
                if (!empty($data[$field][$instance])) {
                    // Logging: Add default data values to logging field list
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
            // Log the data change
            $log_sql = "-- Deleted by EM Framework API"; // What to log for sql?? Make up some fake statements that would have the same effect?
            $log_desc = "Delete all record data for single form"; // DO NOT CHANGE - REDCap relies on this for data history!
            $log_reason = $form_repeating ? "[instance = $instance]" : ""; // REDCap Bug: Instance number is not logged for form deletions!
            REDCap_Logging::logEvent($log_sql, "redcap_data", "UPDATE", $this->record_id, join(",\n", $logging), $log_desc, $log_reason, $this->project->getPermissionsUser(), $project_id, $now, $event_id, $instance);
        }




        $a = "b";

        // Code from DataEntry/index.php
            // DELETE ALL DATA ON SINGLE FORM ONLY

            // elseif ($user_rights['record_delete'] && $_POST['submit-action'] == "submit-btn-deleteform")
            // {
            //     // Delete all responses from data table for this form (do not delete actual record name - will keep same record name)
            //     $sql = "delete from redcap_data where project_id = $project_id
            //             and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
            //             and field_name in (" . prep_implode($eraseFields) . ")" .
            //             ($Proj->hasRepeatingFormsEvents() ? " AND instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
            //     db_query($sql);
            //     // Longitudinal projects only
            //     $sql3 = "";
            //     if ($longitudinal) {
            //         // Check if all forms on this event/instance have gray status icon (implying that we just deleted the only form with data for this event)
            //         $formStatusValues = Records::getFormStatus(PROJECT_ID, array($fetched.$entry_num), null, null, array($_GET['event_id']=>$Proj->eventsForms[$_GET['event_id']]));
            //         $allFormsDeletedThisEvent = true;
            //         foreach ($formStatusValues[$fetched.$entry_num][$_GET['event_id']] as $this_form) {
            //             if (!empty($this_form)) {
            //                 $allFormsDeletedThisEvent = false;
            //                 break;
            //             }
            //         }
            //         if ($allFormsDeletedThisEvent) {
            //             // Now check to see if other events/instances for this record have data
            //             $sql = "select 1 from redcap_data where project_id = $project_id
            //                     and !(event_id = {$_GET['event_id']} and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'").") 
            //                     and record = '".db_escape($fetched.$entry_num)."' limit 1";
            //             $q = db_query($sql);
            //             $otherEventsHaveData = (db_num_rows($q) > 0);
            //             if ($otherEventsHaveData) {
            //                 // Since other events have data for this record, we should go ahead and remove ALL data from this event 
            //                 // (because we might have __GROUPID__ and record ID field stored on backend for this event still)
            //                 $sql3 = "delete from redcap_data where project_id = $project_id
            //                         and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
            //                         and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'");
            //                 db_query($sql3);
            //             }
            //         }
            //     }
            //     // If this form is a survey, then set all survey response timestamps to NULL (or delete row if a non-first repeating instance)
            //     $sql2 = "";
            //     if ($surveys_enabled && isset($Proj->forms[$_GET['page']]['survey_id'])) 
            //     {
            //         $sql2 = "update redcap_surveys_participants p, redcap_surveys_response r
            //                 set r.first_submit_time = null, r.completion_time = null
            //                 where r.participant_id = p.participant_id and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']}
            //                 and r.record = '".db_escape($fetched.$entry_num)."' and p.event_id = {$_GET['event_id']} and r.instance = {$_GET['instance']}";
            //         db_query($sql2);
            //         // For repeating instruments/events, remove this instance from participant list if instance > 1
            //         $setNullTimestamps = true;
            //         if ($_GET['instance'] > 1 && ($Proj->isRepeatingEvent($_GET['event_id']) || $Proj->isRepeatingForm($_GET['event_id'], $_GET['page']))) {
            //             $sql3 = "select p.participant_id from redcap_surveys_participants p, redcap_surveys_response r
            //                     where r.participant_id = p.participant_id and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']}
            //                     and r.record = '".db_escape($fetched.$entry_num)."' and p.event_id = {$_GET['event_id']} and r.instance = {$_GET['instance']}
            //                     limit 1";
            //             $q = db_query($sql3);
            //             if (db_num_rows($q)) {
            //                 $setNullTimestamps = false;
            //                 $participant_id = db_result($q, 0);
            //                 $sql2 = "delete from redcap_surveys_participants where participant_id = $participant_id";
            //                 db_query($sql2);	
            //             }
            //         }
            //         if ($setNullTimestamps) {
            //             // If this form is a survey, then set all survey response timestamps to NULL (or 
            //             $sql2 = "update redcap_surveys_participants p, redcap_surveys_response r
            //                     set r.first_submit_time = null, r.completion_time = null
            //                     where r.participant_id = p.participant_id and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']}
            //                     and r.record = '".db_escape($fetched.$entry_num)."' and p.event_id = {$_GET['event_id']} and r.instance = {$_GET['instance']}";
            //             db_query($sql2);	
            //         }
            //     }
            //     // Log the data change
            //     $log_event_id = Logging::logEvent("$sql; $sql2; $sql3", "redcap_data", "UPDATE", $fetched.$entry_num, implode(",\n",$eraseFieldsLogging), "Delete all record data for single form",
            //                                 "", "", "", true, null, $_GET['instance']);
            //     // Reset Post array
            //     $_POST = array('submit-action'=>$_POST['submit-action'], 'hidden_edit_flag'=>1);
            // }



    }

    /**
     * Deletes a file (marks it for deletion)
     * @param int $edoc_id The document ID
     */
    public function deleteFile($edoc_id) {
        $edoc_id = $this->requireInt($edoc_id);
        $sql = "UPDATE redcap_edocs_metadata SET `delete_date` = ? WHERE `doc_id` = ? AND `project_id` = ?";
        $params = array($this->now(), $edoc_id, $this->project->getProjectId());
        $this->framework->query($sql, $params);
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
            $$q->addInClause("`event_id`", $instances);
        }
        $result = self::toStatementResult($q->execute());
        $locked_instances = array();
        while ($row = $result->fetch_assoc()) {
            $instance = $row["instance"];
            $form = $row["form"];
            $locked_instances[$instance][] = $form;
        }
        return $locked_instances;
    }

    /**
     * Locks non-repeating forms on (repeating) events.
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
            if ($this->project->isFormOnEvent($form, $event_id)) {
                throw new Exception("Form '$form' is not on event $event_id.");
            }
        }
        $event_repeating = $this->project->isEventRepeating($event_id);
        if (!$event_repeating && count($instances) > 1 && $instances[0] !== 1) {
            throw new Exception("Invalid instance parameter for non-repeating event {$event_id}.");
        }


        // TODO - 
        // Need to get all instances of the event with existing data - query for record-id
        if ($event_repeating) {
            throw new Exception("Locking of forms on repeating events is not implemented yet.");
        }

        // Assemble list of forms to lock
        // Get list of locked forms
        // Get list of gray forms

        // Make a diff to get list of forms to actually lock
        
        // Update database

        return;



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
        $project_id = $this->project->getProjectId();
        $now = $this->now();
        $user_id = $this->userId();
        $lock_success = array();
        $lock_fail = array();
        foreach($instances_to_lock as $instance) {
            $sql = "INSERT INTO redcap_locking_data 
                    (`project_id`, `record`, `event_id`, `form_name`, `username`, `timestamp`, `instance`)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $result = $this->framework->query($sql, [
                $project_id,
                $this->record_id,
                $event_id,
                $form,
                $user_id,
                $now,
                $instance
            ]);
            if ($result === true) $lock_success[] = $instance; else $lock_fail[] = $instance;
        }
        // Update log (bulk)
        $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
        if ($this->project->isLongitudinal()) {
            $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
        }
        if (count($lock_success)) {
            $log_entry = str_replace("#INST#", join(", ", $lock_success), $log_entry);
            REDCap_Logging::logEvent($sql, "redcap_locking_data", "LOCK_RECORD", $this->record_id, $log_entry, "Lock instrument", "", $user_id, $project_id, true, $event_id, null, true);
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
        $project_id = $this->project->getProjectId();
        $now = $this->now();
        $user_id = $this->userId();
        $lock_success = array();
        $lock_fail = array();
        foreach($instances_to_lock as $instance) {
            $sql = "INSERT INTO redcap_locking_data 
                    (`project_id`, `record`, `event_id`, `form_name`, `username`, `timestamp`, `instance`)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $result = $this->framework->query($sql, [
                $project_id,
                $this->record_id,
                $event_id,
                $form,
                $user_id,
                $now,
                $instance
            ]);
            if ($result === true) $lock_success[] = $instance; else $lock_fail[] = $instance;
        }
        // Update log (bulk)
        $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
        if ($this->project->isLongitudinal()) {
            $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
        }
        if (count($lock_success)) {
            $log_entry = str_replace("#INST#", join(", ", $lock_success), $log_entry);
            REDCap_Logging::logEvent($sql, "redcap_locking_data", "LOCK_RECORD", $this->record_id, $log_entry, "Lock instrument", "", $user_id, $project_id, true, $event_id, null, true);
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
        $instances_to_unlock = array_intersect($instances, $locked_instances);
        if (!count($instances_to_unlock)) return; // Nothing to do

        // Unlock instances
        $project_id = $this->project->getProjectId();
        $unlock_success = array();
        $unlock_fail = array();
        foreach ($instances_to_unlock as $instance) {
            $sql = "DELETE FROM redcap_locking_data 
                    WHERE `project_id` = ? AND 
                          `record` = ? AND 
                          `event_id` = ? AND 
                          `form_name` = ? AND 
                          `instance` = ?";
            $result = $this->framework->query($sql, [
                $project_id,
                $this->record_id,
                $event_id,
                $form,
                $instance
            ]);
            if ($result === true) {
                // Is the form e-signed? If so, negate the e-signature
                if ($this->isFormInstanceESigned($form, $instance, $event_id)) {
                    // It is probably not necessary to check first, but instead simply negate
                    $this->negateFormInstanceESignature($form, $instance, $event_id);
                }
            }
            if ($result === true) $unlock_success[] = $instance; else $unlock_fail[] = $instance;
        }
        // Update log (bulk)
        $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
        if ($this->project->isLongitudinal()) {
            $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
        }
        if (count($unlock_success)) {
            $log_entry= str_replace("#INST#", join(", ", $unlock_success), $log_entry);
            REDCap_Logging::logEvent($sql, "redcap_locking_data", "LOCK_RECORD", $this->record_id, $log_entry, "Unlock instrument", "", $this->userId(), $project_id, true, $event_id, null, true);
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
        $project_id = $this->project->getProjectId();
        $log_entry = "Record: {$this->record_id}\nForm: {$this->project->getFormDisplayName($form)}\nInstance: #INST#";
        if ($this->project->isLongitudinal()) {
            $log_entry .= "\nEvent: " . html_entity_decode($this->project->getEventDisplayName($event_id), ENT_QUOTES);
        }
        foreach ($instances as $instance) {
            // No need to check if table row exists
            $sql = "DELETE FROM redcap_esignatures 
                    WHERE `project_id` = ? AND 
                        `record` = ? AND 
                        `event_id` = ? AND 
                        `form_name` = ? AND 
                        `instance` = ?";
            $result = $this->framework->query($sql, [
                $project_id,
                $this->record_id,
                $event_id,
                $form,
                $instance
            ]);
            if ($result === true) {
                // Update log
                REDCap_Logging::logEvent($sql, "redcap_esignatures", "ESIGNATURE", $this->record_id, str_replace("#INST#", $instance, $log_entry), "Negate e-signature", "", $this->userId(), $project_id, true, $event_id, null, false);
            }
        }
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
            array_push($parameters, "{$form}_complete");
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
     * @param array $fields An array of field names.
     * @param string $event The name of the event or the (numerical) event id.
     * @param int|array $instances The repeat instance(s) (optional).
     * @return array An associative array (field_name => value).
     * @throws Exception for violations of the project data structure.
     */
    public function getFieldValues($fields, $event, $instances = 1) {
        // Validate input.
        if (!is_array($instances)) $instances = array($instances);
        $mode = $this->validateFields($fields, $event, $instances);
        if ($mode == null) return array();

        $event_id = $this->project->getEventId($event);
        $project_id = $this->project->getProjectId();
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
     * @param array|int|null $instances
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
            throw new \Exception("Invalid instances parameter '{$instances}'.");
        }
        foreach ($instances as $instance) {
            if (!is_integer($instance) || $instances < 1) {
                throw new \Exception("Invalid instance '{$instance}'. Must be an integer > 0.");
            }
        }
        return $instances;
    }

    /**
     * Validates compatibility of "fields, event, instance" combinations with project data structure.
     * 
     * @param array $fields A list of field names.
     * @param string $event The event name of (numerical) event id.
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
                throw new \Exception("Instances must be integers > 0.");
            }
            $max_instance = max($max_instance, $instance);
            $min_instance = min($min_instance, $instance);
        }
        if (count($instances) && $max_instance == 0) {
            throw new \Exception("Invalid instances.");
        }
        // Check event.
        $event_id = $this->project->getEventId($event);
        $project_id = $this->project->getProjectId();
        if ($event_id === null) {
            throw new \Exception("Event '{$event}' does not exist in project '{$project_id}'.");
        }
        if($this->project->isEventRepeating($event)) {
            // All fields on this event?
            foreach ($fields as $field) {
                if (!$this->project->isFieldOnEvent($field, $event)) {
                    throw new \Exception("Field '{$field}' is not on event '{$event}'.");
                }
            }
            $mode = self::REPEAT_EVENT;
        }
        else {
            // Are all fields on the same form?
            $form = $this->project->areFieldsOnSameForm($fields);
            // And if so, is it repeating?
            if ($form && $max_instance > 1 && !$this->project->isFormRepeating($form, $event)) {
                throw new \Exception("Invalid instance(s). Fields are on form '{$form}' which is not repeating on event '{$event}.");
            }
            if (!$form) {
                // Fields are on more than one form. None of the fields must be on a repeating form.
                foreach ($fields as $field) {
                    if ($this->project->isFieldOnRepeatingForm($field, $event)) {
                        throw new \Exception("Must not mix fields that are on non-repeating and repeating forms.");
                    }
                }
            }
            $mode = $form && $this->project->isFormRepeating($form, $event) ? self::REPEAT_FORM : self::NON_REPEATING;
        }
        return $mode;
    }

    #endregion



}
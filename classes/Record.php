<?php namespace DE\RUB\Utility;

use \REDCap;

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
        REDCap::saveData(
            $this->project->getProjectId(), // project_id
            "array",                        // dataFormat
            $data,                          // data
            "overwrite"                     // overwriteBehavior
        );
        $count = $last_instance - $first_instance + 1;
        return "{$event_id}:{$first_instance}:{$last_instance}:{$count}";
    }

    /**
     * Deletes form instances.
     * 
     * @param string $form The form name (it must exist and be a repeating form).
     * @param string $event The name or (numerical) id of the event.
     * @param array $instances An array of the instance numbers to delete.
     * @throws Exception An exception is thrown in case of project data structure violations.
     */
    public function deleteFormInstances($form, $event, $instances) {
        // Check event.
        if (!$this->project->hasEvent($event)) {
            throw new \Exception("Event '{$event}' does not exist in project '{$this->project->getProjectId()}'.");
        }
        // Check form.
        if (!$this->project->hasForm($form) && !$this->project->isFormRepeating($form, $event)) {
            throw new \Exception("Form '{$form}' does not exist or is not repeating in event '{$event}'.");
        }
        // Check instance
        foreach ($instances as $instance) {
            if (!is_integer($instance)|| $instances < 1) {
                throw new \Exception("Invalid instance '{$instance}'. Must be an integer > 0.");
            }
        }
        $event_id = $this->project->getEventId($event);

        global $user_rights;

        // Code from DataEntry/index.php
            // DELETE ALL DATA ON SINGLE FORM ONLY

            // elseif ($user_rights['record_delete'] && $_POST['submit-action'] == "submit-btn-deleteform")
            // {
            //     // Set any File Upload fields as deleted in the edocs table
            //     if ($Proj->hasFileUploadFields) {
            //         $sql = "update redcap_metadata m, redcap_data d, redcap_edocs_metadata e
            //                 set e.delete_date = '".NOW."' where m.project_id = $project_id
            //                 and m.project_id = d.project_id and e.project_id = m.project_id and m.element_type = 'file'
            //                 and d.field_name = m.field_name and d.value = e.doc_id and m.form_name = '".db_escape($_GET['page'])."'
            //                 and d.event_id = {$_GET['event_id']} and d.record = '".db_escape($fetched.$entry_num)."'" .
            //                 ($Proj->hasRepeatingFormsEvents() ? " AND d.instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
            //         db_query($sql);
            //     }
            //     // Get list of all fields with data for this record on this form
            //     $sql = "select distinct field_name from redcap_data where project_id = $project_id
            //             and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
            //             and field_name in (" . prep_implode(array_keys($Proj->forms[$_GET['page']]['fields'])) . ") and field_name != '$table_pk'" .
            //             ($Proj->hasRepeatingFormsEvents() ? " AND instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
            //     $q = db_query($sql);
            //     $eraseFields = $eraseFieldsLogging = array();
            //     while ($row = db_fetch_assoc($q)) {
            //         // Add to field list
            //         $eraseFields[] = $row['field_name'];
            //         // Add default data values to logging field list
            //         if ($Proj->isCheckbox($row['field_name'])) {
            //             foreach (array_keys(parseEnum($Proj->metadata[$row['field_name']]['element_enum'])) as $this_code) {
            //                 $eraseFieldsLogging[] = "{$row['field_name']}($this_code) = unchecked";
            //             }
            //         } else {
            //             $eraseFieldsLogging[] = "{$row['field_name']} = ''";
            //         }
            //     }
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
            array_push($parameters, $this->project->recordIdField());
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
            array_push($parameters, $this->project->recordIdField());
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


    /**
     * Validates compatibility of "fields, event, instance" combinations with project data structure.
     * 
     * @param array $fields A list of field names.
     * @param string $event The event name of (numerical) event id.
     * @param [int] $instances The repeat instance (optional).
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
        if ($max_instance == 0) {
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



}
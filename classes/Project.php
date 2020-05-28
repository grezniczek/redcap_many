<?php namespace DE\RUB\ManyExternalModule;

use \Project as REDCapProject;

class Project
{
    /** @var \ExternalModules\Framework The EM framework */
    private $framework;
    /** @var int The project id */
    private $project_id;
    /** @var REDCapProject */
    private $proj;

    public static function load($framework, $project_id) {
        return new Project($framework, $project_id);
    }

    function __construct($framework, $project_id){
        $this->framework = $framework;
        $this->project_id = $framework->requireInteger($project_id);
        // Get REDCap's Project instance.
        if (isset($GLOBALS["Proj"]) && $GLOBALS["Proj"]->project_id === $this->project_id) {
            $this->proj = $GLOBALS["Proj"];
        }
        else {
            $this->proj = new REDCapProject($project_id);
        }
        $this->proj->getUniqueEventNames();
    }


    #region -- Project Properties ----------------------------------------------------
    
    /**
     * Gets the project id.
     * @return int
     */
    public function getProjectId() {
        return $this->project_id;
    }
    
    /**
     * Indicates whether the project is longitudinal.
     * @return boolean
     */
    public function isLongitudinal() {
        return $this->proj->longitudinal == true;
    }

    /**
     * Gets the name of the record id field.
     * @return string
     */
    public function getRecordIdField() {
        return $this->proj->table_pk;
    }

    /**
     * Gets the number of arms in the project.
     * @return int
     */
    public function getNumArms() {
        return $this->proj->numArms;
    }

    /**
     * Gets the number of events in the project.
     * @return int
     */
    public function getNumEvents() {
        return $this->proj->numEvents;
    }


    #endregion


    #region -- Records ---------------------------------------------------------------

    /**
     * Gets an instance of the Record class.
     * @return Record 
     */
    function getRecord($record_id) {
        if (!class_exists("\DE\RUB\ManyExternalModule\Record")) include_once ("Record.php");
        return new Record($this->framework, $this, $record_id);
    }

    #endregion


    #region -- Events ----------------------------------------------------------------

    /**
     * Checks whether an event exists in the project.
     * @param mixed $event The unique name or numerical id of the event
     * @return boolean
     */
    public function hasEvent($event) {
        if (is_int($event * 1)) {
            return array_key_exists($event * 1, $this->proj->eventInfo);
        }
        else {
            return in_array($event, array_values($this->proj->uniqueEventNames), true);
        }
    }

    /**
     * Gets the event id.
     * If the event does not exist, null will be returned.
     * @param mixed $event The unique event name or the (numerical) event id (can be omitted in non-longitudinal projects)
     * @return integer|null 
     */
    public function getEventId($event = null) {
        if ($event === null && $this->isLongitudinal()) {
            return $this->proj->firstEventId;
        }
        if ($this->hasEvent($event)) {
            if (is_int($event * 1)) {
                return $event * 1;
            }
            else {
                return $this->proj->getEventIdUsingUniqueEventName($event);
            }
        }
        return null;
    }

    /**
     * Checks whether the event is a repeating event.
     * If the event does not exist, null will be returned.
     * @param string $event The unique name or the numerical id of the event 
     * @return boolean|null 
     */
    public function isEventRepeating($event) {
        $event_id = $this->getEventId($event);
        return $event_id === null ? 
            null : $this->proj->isRepeatingEvent($event_id);
    }

    #endregion


    #region -- Forms -----------------------------------------------------------------

    /**
     * Checks whether a form exists in the project.
     * @param string $form The unique form name
     * @return boolean
     */
    public function hasForm($form) {
        return array_key_exists($form, $this->proj->form);
    }

    /**
     * Gets the name of the form the field is on.
     * Returns null if the field does not exist.
     * @param string $field The field name
     * @return string
     */
    public function getFormByField($field) {
        $metadata = @$this->proj->metadata[$field];
        return empty($metadata) ? null : $metadata["form_name"];
    }

    /**
     * Checks whether a form is on a specific event.
     * If the form or event does not exist, null is returned.
     * @param string $form The unique form name
     * @param string $event The unique event name or the (numerical) event id (can be omitted on non-longitudinal projects)
     * @return boolean|null
     */
    public function isFormOnEvent($form, $event = null) {
        $event_id = $this->getEventId($event);
        if ($event_id !== null && $this->hasForm($form)) {
            return array_search($form, $this->proj->eventsForms[$event_id]) !== false;
        }
        return null;
    }

    /**
     * Checks whether a form is repeating.
     * If the form or event does not exist, null is returned.
     * @param string $form The unique form name
     * @param string $event The unique event name or (numerical) event id (can be omitted in non-longitudianl projects)
     * @return boolean|null
     */
    public function isFormRepeating($form, $event = null) {
        $event_id = $this->getEventId($event);
        if ($event_id !== null && $this->isFormOnEvent($form, $event_id)) {
            return $this->proj->isRepeatingForm($event_id, $form);
        }
        return null;
    }

    #endregion


    #region -- Fields ----------------------------------------------------------------

    /**
     * Checks whether a field exists in the project.
     * @param string $field The unique field name
     * @return boolean
     */
    public function hasField($field) {
        return array_key_exists($field, $this->proj->metadata);
    }

    /**
     * Checks whether fields exist and are all on the same form.
     * If so, the name of the form is returned, otherwise false.
     * In case of an empty field list, false is returned.
     * @param array $fields List of field names.
     * @return string|boolean Form name or false.
     */
    public function areFieldsOnSameForm($fields) {
        $forms = array();
        foreach ($fields as $field) {
            $forms[$this->getFormByField($field)] = null;
        }
        return count($forms) == 1 ? array_key_first($forms) : false;
    }

    /**
     * Checks whether a field is on a repeating form.
     * If the field or event does not exists, null is returned.
     * @param string $field The field name
     * @param strign $event The unique event name or (numerical) event id (can be omitted in non-longitudinal projects)
     * @return boolean|null
     */
    public function isFieldOnRepeatingForm($field, $event = null) {
        if ($this->hasField($field)) {
            $form = $this->getFormByField($field);
            return $this->isFormRepeating($form, $event);
        }
        return null;
    }

    /**
     * Checks whether a field is on a specific event.
     * If the field or event does not exists, null is returned.
     * @param string $field The field name.
     * @param strign $event The unique event name or the (numerical) event id (can be omitted on non-longitudinal projects)
     * @return boolean|null
     */
    public function isFieldOnEvent($field, $event = null) {
        $event_id = $this->getEventId($event);
        if ($event_id !== null && $this->hasField($field)) {
            return $this->isFormOnEvent($this->getFormByField($field), $event_id);
        }
        return null;
    }



    #endregion


    #region -- Field Metadata --------------------------------------------------------






    #endregion








    /**
     * Gets the field metadata.
     * If the field does not exist, null is returned.
     * 
     * @param string $field The field name.
     * @return array Field metadata (as in global $Proj).
     */
    function getFieldMetadata($field) {
        $pds = $this->getProjectDataStructure();
        if ($this->hasField($field)) {
            return $pds["fields"][$field]["metadata"];
        }
        return null;
    }

    /**
     * Gets the field type.
     * If the field does not exist, null is returned.
     * 
     * @param string $field The field name.
     * @return string 
     */
    function getFieldType($field) {
        $metadata = $this->getFieldMetadata($field);
        if ($metadata) {
            return $metadata["element_type"];
        }
        return null;
    }

    /**
     * Gets the field validation.
     * If the field does not exist, null is returned.
     * 
     * @param string $field The field name.
     * @return string 
     */
    function getFieldValidation($field) {
        $metadata = $this->getFieldMetadata($field);
        if ($metadata) {
            return $metadata["element_validation_type"];
        }
        return null;
    }

    /**
     * Gets the repeating forms and events in the current or specified project.
     * 
     * The returned array is structured like so:
     * [
     *   "forms" => [
     *      event_id => [
     *         "form name", "form name", ...
     *      ],
     *      ...
     *   ],
     *   "events" => [
     *      event_id => [
     *        "form name", "form name", ...
     *      ],
     *      ...
     *   ] 
     * ]
     * 
     * @param int|string|null $pid The project id (optional).
     * @return array An associative array listing the repeating forms and events.
     * @throws Exception From requireProjectId if no project id can be found.
     */
    function getRepeatingFormsEvents($pid = null) {

        $pid = $pid === null ? $this->getProjectId() : $this->framework->requireProjectId($pid);
        
        $result = $this->framework->query('
            select event_id, form_name 
            from redcap_events_repeat 
            where event_id in (
                select m.event_id 
                from redcap_events_arms a
                join redcap_events_metadata m
                on a.arm_id = m.arm_id and a.project_id = ?
            )', $pid);

        $forms = array(
            "forms" => array(),
            "events" => array()
        );
        while ($row = $result->fetch_assoc()) {
            $event_id = $row["event_id"];
            $form_name = $row["form_name"];
            if ($form_name === null) {
                // Entire repeating event. Add all forms in it.
                $forms["events"][$event_id] = $this->getEventForms($event_id);
            }
            else {
                $forms["forms"][$event_id][] = $form_name;
            }
        }
        return $forms;
    }

    /**
     * Gets the names of the forms in the current or specified event.
     * 
     * @param int|null $event_id The event id (optional)
     * @return array An array of form names.
     * @throws Exception From requireProjectId or ExternalModules::getEventId if event_id, project_id cannot be deduced or multiple event ids are in a project.
     */
    function getEventForms($event_id = null) {
        if($event_id === null){
            $event_id = $this->framework->getEventId();
        }
        $forms = array();
        $result = $this->framework->query('
            select form_name
            from redcap_events_forms
            where event_id = ?
        ', $event_id);
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row["form_name"];
        }
        return $forms;
    }


    /**
     * Gets the project structure (arms, events, forms, fields) of the current or specified project.
     * 
     * The returned array is structured like so:
     * [
     *   "pid" => "project_id", 
     *   "record_id" => "record_id_field_name",
     *   "longitudinal" => true|false,
     *   "forms" => [
     *      "form name" => [
     *          "name" => "form name",
     *          "repeating" => true|false,
     *          "repeating_event" => true|false,
     *          "arms" => [
     *              arm_id => [ 
     *                  "id" => arm_id 
     *              ], ...
     *          ],
     *          "events" => [
     *              event_id => [
     *                  "id" => event_id,
     *                  "name" => "event name",
     *                  "repeating" => true|false
     *              ], ...
     *          ],
     *          "fields" => [
     *              "field name", "field name", ...
     *          ]
     *      ], ...
     *   ],
     *   "events" => [
     *      event_id => [
     *          "id" => event_id,
     *          "name" => "event name",
     *          "repeating" => true|false,
     *          "arm" => arm_id,
     *          "forms" => [
     *              "form_name" => [
     *                  "name" => "form_name",
     *                  "repeating" => true|false
     *              ], ...
     *          ]
     *      ], ...
     *   ],
     *   "arms" => [
     *      arm_id => [
     *          "id" => arm_id
     *          "events" => [
     *              event_id => [
     *                  "id" => event_id,
     *                  "name" => "event name"
     *              ], ...
     *          ],
     *          "forms" => [
     *              "form name" => [
     *                  "name" => "form name"
     *              ], ...
     *          ]
     *      ], ...
     *   ],
     *   "fields" => [
     *      "field name" => [
     *          "name" => "field name",
     *          "form" => "form name",
     *          "repeating_form" => true|false,
     *          "repeating_event" => true|false,
     *          "events" => [
     *              event_id => [ 
     *                  (same as "events" => event_id -- see above)
     *              ], ...
     *          ],
     *          "metadata" => [
     *              (same as in $Proj)
     *          ]
     *      ], ...
     *   ]
     * ] 
     * @param int|string|null $pid The project id (optional).
     * @return array An array containing information about the project's data structure.
     */
    function getProjectDataStructure($pid = null) {

        $pid = $pid === null ? $this->getProjectId() : $this->framework->requireProjectId($pid);

        // Check cache.
        if (array_key_exists($pid, self::$ProjectDataStructureCache)) return self::$ProjectDataStructureCache[$pid];

        // Prepare return data structure.
        $ps = array(
            "pid" => $pid,
            "project" => $this->proj,
            "longitudinal" => $this->proj->longitudinal,
            "multiple_arms" => $this->proj->multiple_arms,
            // Events are ordered by day_offset in redcap_events_metadata
            "first_event_id" => array_key_first($this->proj->events[1]["events"]), 
            "record_id" => $this->framework->getRecordIdField($pid),
            "forms" => array(),
            "events" => array(),
            "arms" => array(),
            "fields" => array(),
        );

        // Gather data - arms, events, forms.
        // Some of this might be extractable from $proj, but this is just easier.
        $params = array($pid);
        $sql = "SELECT a.arm_id, m.event_id, f.form_name
                FROM redcap_events_arms a
                JOIN redcap_events_metadata m
                ON a.arm_id = m.arm_id AND a.project_id = ?
                JOIN redcap_events_forms f
                ON f.event_id = m.event_id";
        if (!$ps["longitudinal"]) {
            // Limit to the "first" event (i.e. the one in Project) - there may be more if the 
            // project has ever been longitudinal.
            $sql .= " AND m.event_id = ?";
            array_push($params, $ps["first_event_id"]);
        }
        $result = $this->framework->query($sql, $params);
        while ($row = $result->fetch_assoc()) {
            $event_id = $row["event_id"] * 1;
            $event_name = $proj->uniqueEventNames[$event_id];
            $arm_id = $row["arm_id"] * 1;
            $form_name = $row["form_name"];

            $ps["arms"][$arm_id]["id"] = $arm_id;
            $ps["arms"][$arm_id]["events"][$event_id] = array(
                "id" => $event_id,
                "name" => $event_name,
            );
            $ps["arms"][$arm_id]["forms"][$form_name] = array(
                "name" => $form_name
            );
            $ps["events"][$event_id]["id"] = $event_id;
            $ps["events"][$event_id]["name"] = $event_name;
            $ps["events"][$event_id]["repeating"] = false;
            $ps["events"][$event_id]["arm"] = $arm_id;
            $ps["events"][$event_id]["forms"][$form_name] = array(
                "name" => $form_name,
                "repeating" => false
            );
            $ps["forms"][$form_name]["name"] = $form_name;
            $ps["forms"][$form_name]["repeating"] = false;
            $ps["forms"][$form_name]["repeating_event"] = false;
            $ps["forms"][$form_name]["arms"][$arm_id] = array(
                "id" => $arm_id
            );
            $ps["forms"][$form_name]["events"][$event_id] = array(
                "id" => $event_id,
                "name" => $event_name,
                "repeating" => false
            );
        }
        // Gather data - fields. Again, this could be got from $proj, but this is more straightforward to process.
        // TODO: Do indeed get this from Project. This is more complicated than it seems.
        
        $result = $this->framework->query("
            SELECT field_name, form_name
            from redcap_metadata
            where project_id = ?
            order by field_order asc
        ", $pid);
        while ($row = $result->fetch_assoc()) {
            $ps["fields"][$row["field_name"]] = array(
                "name" => $row["field_name"],
                "form" => $row["form_name"],
                "repeating_form" => false,
                "repeating_event" => false,
            );
            $ps["forms"][$row["form_name"]]["fields"][] = $row["field_name"];
        }
        // Gather data - repeating forms, events.
        $repeating = $this->getRepeatingFormsEvents($pid);
        foreach ($repeating["forms"] as $eventId => $forms) {
            foreach ($forms as $form) {
                $ps["events"][$eventId]["forms"][$form]["repeating"]= true;
                $ps["forms"][$form]["repeating"] = true;
                // Augment fields.
                foreach ($ps["fields"] as $field => &$field_info) {
                    if ($field_info["form"] == $form) {
                        $field_info["repeating_form"] = true;
                    }
                }
            }
        }
        foreach ($repeating["events"] as $eventId => $forms) {
            $ps["events"][$eventId]["repeating"] = true;
            foreach ($forms as $form) {
                $ps["forms"][$form]["repeating_event"] = true;
                $ps["forms"][$form]["events"][$eventId]["repeating"] = true;
                // Augment fields.
                foreach ($ps["fields"] as $field => &$field_info) {
                    if ($field_info["form"] == $form) {
                        $field_info["repeating_event"] = true;
                    }
                }
            }
        }
        // Augment fields with events.
        foreach ($ps["forms"] as $formName => $formInfo) {
            foreach ($formInfo["fields"] as $field) {
                foreach ($formInfo["events"] as $eventId => $_) {
                    $ps["fields"][$field]["events"][$eventId] = $ps["events"][$eventId];
                }
            }
        }
        // Augment fields with field metadata.
        foreach ($ps["fields"] as $field => &$field_data) {
            $field_data["metadata"] = $proj->metadata[$field];
        }

        // Add to cache.
        self::$ProjectDataStructureCache[$pid] = $ps;

        return $ps;
    }

    private static $ProjectDataStructureCache = array();



}
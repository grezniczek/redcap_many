<?php

namespace DE\RUB\ManyExternalModule;

use ExternalModules\AbstractExternalModule;
use \DE\RUB\Utility\InjectionHelper;
use \DE\RUB\Utility\Project;

class ManyExternalModule extends AbstractExternalModule
{

    private const MANY_EM_SESSION_KEY_RECORDS = "many-em-selection-store-records";
    private const MANY_EM_SESSION_KEY_INSTANCES = "many-em-selection-store-instances";

    function redcap_every_page_before_render($project_id)
    {

        // Can we show fake data entry pages?
        if (PAGE == "DataEntry/index.php") {
            global $Proj;

            if ($_GET["page"] == "_fake") {
                $allowed = array("record_id", "yesnofield", "yesnoradio1", "record_info_complete", "locktime", "form_2_complete");
                $Proj->forms["_fake"] = array(
                    "form_number" => 1,
                    "menu" => "Edit Many",
                    "has_branching" => 0,
                    "fields" => array(),
                );
                foreach ($allowed as $key) {
                    $Proj->forms["_fake"]["fields"][$key] = $Proj->metadata[$key]["element_label"];
                }
            }

            // YES - We can!

            // However, we need to prevent the "Save" event to get to REDCap
            // This can be achieved by setting the <form>'s action attribute to the plugin page

            // Hide Actions, Save & .. Button
            // Change "Save & Exit Form" to "Save Many"
            // Hide "Lock this instrument?" row
            // Hide floating save menu
            // Hide "Adding new ..."
            // Set record id to Many logo
            // Multiple _complete fields? Add respective form name to "Form Status"

            // Hide Record entry in left side menu!

        }
    }

    function redcap_every_page_top($project_id)
    {


        // TODO - limit scope of this hook

        $debug = $this->getProjectSetting("debug-mode") === true;

        // Inject CSS and JS.
        if (!class_exists("\RUB\Utility\InjectionHelper")) include_once("classes/InjectionHelper.php");
        $ih = InjectionHelper::init($this);
        $ih->js("js/many-em.js");
        $ih->css("css/many-em.css");

        $dto_selected = $this->loadSelectedRecords($project_id);

        // Link to plugin page in the Data Collection menu.
        $href = $this->getUrl("many.php");
        $name = $this->getConfig()["name"];
        $updateUrl = $this->getUrl("ajax/update-selection.php");
        $dto_link = array(
            "href" => $href,
            "name" => $name,
            "clearText" => "Clear", // tt-fy
            "addText" => "Add this record", // tt-fy
            "removeText" => "Remove this record", // tt-fy
        );

        // Record Status Dashboard.
        $dto_rsd = array(
            "init" => strpos(PAGE, "DataEntry/record_status_dashboard.php") !== false,
            "activate" => $this->getProjectSetting("rsd-active") === true,
            "apply" => "Apply", // tt-fy
            "restore" => "Restore", // tt-fy
            "addAll" => "Add all", // tt-fy
            "removeAll" => "Remove all", // tt-fy
        );

        // Record Home Page.
        $dto_rhp = array(
            "init" => false,
            "activate" => $this->getProjectSetting("rhp-active") === true,
            "rit" => array(),
            "viewPresets" => array(),
            "updatePresets" => array(),
            "deleteConfirmTitle" => $this->tt("modal_delete_instances_title"),
            "deleteConfirmText" => $this->tt("modal_delete_instances_text"),
        );
        // Do we have a record?
        if (strpos(PAGE, "DataEntry/record_home.php") !== false) {
            $record_id = isset($_GET["id"]) && !isset($_GET["auto"]) ? $_GET["id"] : null;
            if ($record_id != null) {
                $dto_rhp["init"] = true;
                // Use Project Data Structure to get
                // all repeating forms on all events and assemble a list
                // of ids like "repeat_instrument_table-80-repeating_store"
                // i.e. "repeat_instrument_table-" + event_id + "-" + form name
                // Then, JS side can use this to add UI elements
                if (!class_exists("\DE\RUB\Utility\Project")) include_once("classes/Project.php");
                /** @var \DE\RUB\Utility\Project */
                $project = Project::load($this->framework, $project_id);
                $repeating = $project->getRepeatingFormsEvents();
                $dto_rhp["rit"] = array();
                foreach ($repeating["forms"] as $event_id => $forms) {
                    foreach ($forms as $form) {
                        $rit_key = "repeat_instrument_table-{$event_id}-{$form}";
                        $dto_rhp["rit"][$rit_key] = $this->loadSelectedInstances($project_id, $record_id, "$event_id", $form);
                    }
                }
                $this->includeDeleteConfirmationModal();
            }
        }
        // Instance view presets - TODO
        $dto_rhp["viewPresets"] = array(
            array(
                "id" => 1,
                "name" => "Test",
                "fields" => array("lap_id", "lap_elapsed")
            )
        );


        // Transfer data to the JavaScript implementation.
?>
        <script>
            var DTO = window.ExternalModules.ManyEM_DTO;
            DTO.debug = <?= json_encode($debug) ?>;
            DTO.name = <?= json_encode($name) ?>;
            DTO.updateUrl = <?= json_encode($updateUrl) ?>;
            DTO.link = <?= json_encode($dto_link) ?>;
            DTO.selected = <?= json_encode($dto_selected) ?>;
            DTO.rsd = <?= json_encode($dto_rsd) ?>;
            DTO.rhp = <?= json_encode($dto_rhp) ?>;
        </script>
    <?php
    }


    private function loadSelectedRecords($pid)
    {
        return isset($_SESSION[self::MANY_EM_SESSION_KEY_RECORDS][$pid]) ?
            $_SESSION[self::MANY_EM_SESSION_KEY_RECORDS][$pid] :
            array();
    }

    private function saveSelectedRecords($pid, $selected)
    {
        $_SESSION[self::MANY_EM_SESSION_KEY_RECORDS][$pid] = $selected;
    }

    public function updateRecords($diff)
    {
        $pid = $this->getProjectId();
        $records = $this->loadSelectedRecords($pid);
        foreach ($diff as $record_id => $is_selected) {
            if ($is_selected) {
                array_push($records, "$record_id");
            } else {
                $pos = array_search("$record_id", $records, true);
                if ($pos !== false) {
                    array_splice($records, $pos, 1);
                }
                $this->clearAllInstances("$record_id");
            }
        }
        $this->saveSelectedRecords($pid, array_values(array_unique($records, SORT_STRING)));
    }

    public function clearRecords()
    {
        $pid = $this->getProjectId();
        $this->saveSelectedRecords($pid, array());
        $this->clearAllInstances();
    }

    private function clearAllInstances($record_id = null)
    {
        $pid = $this->getProjectId();
        if ($record_id) {
            unset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id]);
        } else {
            unset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid]);
        }
    }

    private function loadSelectedInstances($pid, $record_id, $event_id, $form)
    {
        return isset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form]) ?
            $_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form] :
            array();
    }

    private function saveSelectedInstances($pid, $record_id, $event_id, $form, $instances)
    {
        if (!isset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid]))
            $_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid] = array();
        if (!isset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id]))
            $_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id] = array();
        if (!isset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id]))
            $_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id] = array();
        $_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id][$form] = $instances;
    }

    private function deleteSelectedInstancesForRecord($pid, $record_id)
    {
        unset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id]);
    }

    private function deleteSelectedInstancesForRecordEvent($pid, $record_id, $event_id)
    {
        unset($_SESSION[self::MANY_EM_SESSION_KEY_INSTANCES][$pid][$record_id][$event_id]);
    }

    public function updateInstances($record_id, $event_id, $form, $diff)
    {
        $pid = $this->getProjectId();
        $instances = $this->loadSelectedInstances($pid, $record_id, $event_id, $form);
        foreach ($diff as $instance => $is_selected) {
            if ($is_selected) {
                array_push($instances, "$instance");
            } else {
                $pos = array_search("$instance", $instances, true);
                if ($pos !== false) {
                    array_splice($instances, $pos, 1);
                }
            }
        }
        $this->saveSelectedInstances($pid, $record_id, $event_id, $form, array_values(array_unique($instances, SORT_STRING)));
    }

    public function clearInstances($record_id, $event_id, $form)
    {
        $pid = $this->getProjectId();
        if (empty($event_id) && empty($form)) {
            $this->deleteSelectedInstancesForRecord($pid, $record_id);
        } else if (empty($form)) {
            $this->deleteSelectedInstancesForRecordEvent($pid, $record_id, $event_id);
        } else {
            $this->saveSelectedInstances($pid, $record_id, $event_id, $form, array());
        }
    }


    private function includeDeleteConfirmationModal()
    {
        /** @var \ExternalModules\Framework */
        $fw = $this->framework;
        ?>
        <div class="modal fade many-em-delete-confirmation-modal" data-backdrop="static" tabindex="-1" role="dialog" aria-labelledby="many-em-delete-confirmation-model-staticBackdropLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="many-em-delete-confirmation-model-staticBackdropLabel">Title</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="<?=$fw->tt("modal_close")?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        ...
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><?=$fw->tt("modal_cancel")?></button>
                        <button type="button" class="btn btn-danger"><?=$fw->tt("modal_delete")?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
} // ManyExternalModule

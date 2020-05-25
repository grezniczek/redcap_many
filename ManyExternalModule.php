<?php namespace DE\RUB\ManyExternalModule;

use ExternalModules\AbstractExternalModule;
use \DE\RUB\Utility\InjectionHelper;


class ManyExternalModule extends AbstractExternalModule {

    function redcap_every_page_before_render($project_id) {
        
        // Can we show fake data entry pages?
        if (PAGE == "DataEntry/index.php") {
            global $Proj;

            if ($_GET["page"] == "_fake") {
                $allowed = array ("record_id", "yesnofield", "yesnoradio1", "record_info_complete", "locktime", "form_2_complete");
                $Proj->forms["_fake"] = array (
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

    function redcap_every_page_top($project_id) {


        // TODO - limit scope of this hook
        
        $debug = $this->getProjectSetting("debug-mode") === true;

        // Inject CSS and JS.
        if (!class_exists("\RUB\Utility\InjectionHelper")) include_once("classes/InjectionHelper.php");
        $ih = InjectionHelper::init($this);
        $ih->js("js/many-em.js");
        $ih->css("css/many-em.css");


        // DEMO - fake counter
        if (!isset($_SESSION["many-em"][$project_id])) {
            $_SESSION["many-em"][$project_id] = array ("count" => 1);
        }
        else {
            $_SESSION["many-em"][$project_id]["count"]++;
        }
        $count = $_SESSION["many-em"][$project_id]["count"];


        $dto_selected = array("R1", "R2");




        // Link to plugin page in the Data Collection menu.
        $href = $this->getUrl("many.php");
        $name = $this->getConfig()["name"];
        $updateUrl = $this->getUrl("ajax/update-selection.php");
        $dto_link = array(
            "href" => $href,
            "name" => $name,
            "clearText" => "Clear", // tt-fy
        );

        // Record Status Dashboard.
        $dto_rsd = array(
            "init" => strpos(PAGE, "DataEntry/record_status_dashboard.php") !== false,
            "activate" => $this->getProjectSetting("rsd-active") === true,
            "updateSelection" => "Update selection", // tt-fy
            "addAll" => "Add all", // tt-fy
            "removeAll" => "Remove all", // tt-fy
        );

        // Transfer data to the JavaScript implementation.
        ?>
        <script>
            var DTO = window.ExternalModules.ManyEM_DTO;
            DTO.debug = <?=json_encode($debug)?>;
            DTO.name = <?=json_encode($name)?>;
            DTO.updateUrl = <?=json_encode($updateUrl)?>;
            DTO.link = <?=json_encode($dto_link)?>;
            DTO.selected = <?=json_encode($dto_selected)?>;
            DTO.rsd = <?=json_encode($dto_rsd)?>;
        </script>
        <?php
    }

    public function updateSelection($selected) {
        $pid = $this->getProjectId();



    }

} // ManyExternalModule

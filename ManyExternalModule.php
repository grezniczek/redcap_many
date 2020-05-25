<?php namespace DE\RUB\ManyExternalModule;

use ExternalModules\AbstractExternalModule;

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
        if (!isset($_SESSION["many-em"][$project_id])) {
            $_SESSION["many-em"][$project_id] = array ("count" => 1);
        }
        else {
            $_SESSION["many-em"][$project_id]["count"]++;
        }
        $count = $_SESSION["many-em"][$project_id]["count"];
        $href = $this->getUrl("many.php");
    
        ?>
        <style>
            .many-em-logo {
                color: green !important;
            }
            .many-em-menu-link-count {
                text-indent: 0;
                margin-left: 0.5em;
                font-size: 11px;
                font-weight: normal;
                padding-left: 0.5em;
                padding-right: 0.5em;
            }
        </style>
        <script>
            ;(function() {
                function addLink() {
                    var $menu = $('<div></div>')
                        .addClass('hang')
                        .css('position', 'relative')
                        .append($('<i></i>').addClass('far fa-check-square fs14 many-em-logo'))
                        .append('&nbsp;&nbsp;')
                        .append($('<a></a>')
                            .attr('href','<?=$href?>')
                            .text('Many'))
                        .append($('<span></span>')
                            .addClass('badge badge-secondary many-em-menu-link-count')
                            .text('<?=$count?>'))
                    var $ip = $('#projMenuDataCollection').parent().parent().find('div.hang').last()
                    $menu.insertAfter($ip.next('.menuboxsub').length ? $ip.next() : $ip)
                }
                $(addLink)
            })();
        </script>
        <?php


    }



} // ManyExternalModule

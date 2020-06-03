<?php namespace DE\RUB\MultipleExternalModule;

class MultipleEM_ClearSelectionAjax
{
    /**
     * @param MultipleExternalModule $module
     */
    public static function execute($module) {

        $debug = $module->getProjectSetting("debug-mode") === true;

        $data = file_get_contents("php://input");
        $items = explode("&redcap_csrf_token=", $data);
        $payload = "";
        foreach ($items as $item) {
            if (substr($item, 0, 8) == "payload=") {
                $payload = substr($item, 8);
                break;
            }
        }

        $response = array("success" => true);
        
        try {
            $update = json_decode($payload, true);
            if ($update == null) {
                throw new \Exception("Invalid payload received.");
            }
            switch ($update["command"]) {
                // Records - Record Status Dashboard actions
                case "update-records":
                    $module->updateRecords($update["diff"]);
                    break;
                case "remove-all-records":
                    $module->clearRecords();
                    break;
                // Forms - Record Home Page actions (single record)
                case "update-record-forms-selection":
                    $module->updateForms($update["record"], $update["diff"]["fei"]);
                    $module->updateInstances($update["record"], $update["diff"]["rit"]);
                    break;
                case "clear-record-forms-selection":
                    $module->clearAllForms($update["record"]);
                    $module->clearAllInstances($update["record"]);
                    break;
                case "delete-record-forms":
                    $module->deleteRecordForms($update["record"]);
                    break;
                case "lock-record-forms": 
                    $module->setFormsLockState($update["record"], true);
                    break;
                case "unlock-record-forms": 
                    $module->setFormsLockState($update["record"], false);
                    break;
            }
        }
        catch (\Throwable $e) {
            $response = array(
                "success" => false,
                "error" => "The operation failed.",
            );
            if ($debug) {
                $response["exception"] = $e->getMessage();
                $response["trace"] = $e->getTraceAsString();
            }
        }
        finally {
            print json_encode($response);
        }
    }
}
MultipleEM_ClearSelectionAjax::execute($module);
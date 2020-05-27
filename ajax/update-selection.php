<?php namespace DE\RUB\ManyExternalModule;

class ManyEM_ClearSelectionAjax
{
    /**
     * @param ManyExternalModule $module
     */
    public static function execute($module) {

        $data = file_get_contents("php://input");
        $items = explode("&redcap_csrf_token=", $data);
        $payload = "";
        foreach ($items as $item) {
            if (substr($item, 0, 8) == "payload=") {
                $payload = substr($item, 8);
                break;
            }
        }
        $update = json_decode($payload, true);

        switch ($update["command"]) {
            case "update-records":
                $module->updateRecords($update["diff"]);
                break;
            case "remove-all-records":
                $module->clearRecords();
                break;
            case "update-instances":
                $module->updateInstances($update["record"], $update["event"], $update["form"], $update["diff"]);
                break;
            case "remove-all-instances":
                $module->clearInstances($update["record"], $update["event"], $update["form"]);
                break;
            case "delete-record-instances":
                $module->deleteRecordInstances($update["record"]);
                break;
        }

        print json_encode(array("success" => true));
    }
}
ManyEM_ClearSelectionAjax::execute($module);
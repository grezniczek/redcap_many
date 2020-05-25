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
        $selected = json_decode($payload, true);

        $module->updateSelection($selected);
        print json_encode(array(
            "success" => true
        ));
    }
}
ManyEM_ClearSelectionAjax::execute($module);
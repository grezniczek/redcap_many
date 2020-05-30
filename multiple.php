<?php namespace DE\RUB\MultipleExternalModule;
//
// Multiple EM - Plugin
//
class MultipleEM_PluginPage
{
    /** @var MultipleExternalModule $module Multiple EM instance */
    private $module;
    
    /**
     * @param MultipleExternalModule $module Multiple EM instance
     */
    public function __construct($module) {
        $this->module = $module;
    }

    public function render() {
        /** @var \ExternalModules\Framework $fw */
        $fw = $this->module->framework;

        // TODO
        print "TODO";
    }
}

//
// REDCap Header
//
require_once APP_PATH_DOCROOT . "ProjectGeneral/header.php";
$multiple = new MultipleEM_PluginPage($module);
//
// Plugin Page
?>
<div class="multiple-em-pagecontainer">
    <h3><i class="far fa-check-square multiple-em-logo"></i> Multiple</h3>
    <?php $multiple->render(); ?>
</div>
<?php 
//
// REDCap Footer
//
require_once APP_PATH_DOCROOT . "ProjectGeneral/footer.php";

<?php
/**
 * The module endpoint RentADriver calls (signed; see lib/Core.php). Not a page: JSON in, JSON out.
 *
 * @license GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RentadriverApiModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;
    public $content_only = true;

    public function postProcess()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            \RentADriver\StoreModule\Core::respond(array(405, array('ok' => false, 'error' => 'method_not_allowed')));
        }
        $raw = (string) file_get_contents('php://input');
        \RentADriver\StoreModule\Core::respond($this->module->core()->handle($raw, array('\RentADriver\StoreModule\Core', 'serverHeader')));
    }
}

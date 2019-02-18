<?php

namespace Motion\APIBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Process\Process;

class APIController extends Controller{

    public $apiSecret   = "YourSecret";
    public $bypass      = "ByPassString";
    public $interval    = 300;

    public function indexAction(Request $request){
        $this->_authenticate($request);

        return $this->render("MotionAPIBundle:Content:index.html.twig");
    }

    public function setStatusAction(Request $request, $status){
        // Authenticate request
        $validated = $this->_authenticate($request);

        if ($validated){

            // check current status
            $currentStatus = $this->_getStatus();
           
            // Work out what action to take
            switch($status){
                case "on":                
                    // Supress alarm on start up
                    if ($currentStatus == "off"){
                        $output = shell_exec("service motion start");
                        $message = "Starting motion detection daemon";
                        $process = new Process($this->_startup());
                        $process->start();
                    } else {
                        $message = "Motion detection daemon is already running";
                        $output = $message . ": command not executed.";
                    }

                    break;

                case "off":
                    if ($currentStatus == "on"){
                        $output = shell_exec("service motion stop");
                        $message = "Stopping motion detection daemon";
                    } else {
                        $message = "Motion detection daemon is not running";
                        $output = $message . ": command not executed.";
                    }

                    break;

                default:
                    $status = "Invalid";
                    $output = "";
                    $message = "ERROR - Invalid status request";
            }

            $response = new Response(json_encode(array('status' => $status, 'message' => $message, 'output' => $output)));
            $response->headers->set('Content-Type', 'application/json');

        } else {
            $response = $this->_accessDenied();
        }

        return $response;
    }

    public function getStatusAction(Request $request){
         // Authenticate request
        $validated = $this->_authenticate($request);

        if ($validated){

            $message = "Motion detection daemon is not running";
            
            $status = $this->_getStatus();

            // Change message if motion is on
            if ($status == "on"){
                $message = "Motion detection daemon is running";
            }

            $response = new Response(json_encode(array('status' => $status, 'message' => $message)));
            $response->headers->set('Content-Type', 'application/json');

        } else {
            $response = $this->_accessDenied();
        }  

        return $response;
    }

    public function clearAction(Request $request, $type){
        // Authenticate request
        $validated = $this->_authenticate($request);

        if ($validated){

            $status = $type;

            $message = $this->_clear($type);

            $response = new Response(json_encode(array('status' => $status, 'message' => $message)));
            $response->headers->set('Content-Type', 'application/json');
        } else {
            $response = $this->_accessDenied();
        }  

        return $response;
    }

    public function lockAction(Request $request){
        // Authenticate request
        $validated = $this->_authenticate($request);

        if ($validated){

            $status = "Lock";

            $message = $this->_lock();

            $response = new Response(json_encode(array('status' => $status, 'message' => $message)));
            $response->headers->set('Content-Type', 'application/json');
        } else {
            $response = $this->_accessDenied();
        }  

        return $response;
    }

    public function unlockAction(Request $request){
        // Authenticate request
        $validated = $this->_authenticate($request);

        if ($validated){

            $status = "Unlock";

            $message = $this->_unlock();

            $response = new Response(json_encode(array('status' => $status, 'message' => $message)));
            $response->headers->set('Content-Type', 'application/json');
        } else {
            $response = $this->_accessDenied();
        }  

        return $response;
    }

    private function _accessDenied(){
        //throw new BadCredentialsException('Authentication failure');
        $response = new Response(json_encode(array('status' => "error", 'message' => "Authentication Failure", 'output' => "Authentication Failer: Access Denied")));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    private function _authenticate(Request $request){
        $response = false;
        $apiKey = $request->query->get('apikey');
        $bypass = $request->query->get('bypass');

        $validKey = $this->_validateAPIKey($apiKey);

        if ($validKey == true || $bypass == $this->bypass){
            $response = true;
        }

        return $response;
    }

    private function _validateAPIKey($apiKey){
        $apiSecret      = $this->apiSecret;
        $hours          = date('H');
        $minutes        = date('i');
        $epoch          = mktime($hours, $minutes);
        $validKey       = false;

        // Split out the epoch from the apiKey
        $aAPIKey        = explode(':', $apiKey);
        
        // Only deal with a two part apiKey
        if (sizeof($aAPIKey) == 2){
            // Work out expect hash based on passed epoch
            $expected   = md5($aAPIKey[0].$apiSecret);

            // Check hashes match and epoch is 
            if ((intval($aAPIKey[0]) < ($epoch+$this->interval) && intval($aAPIKey[0]) > ($epoch-$this->interval)) &&$expected == $aAPIKey[1]){
                $validKey = true;
            }
        }

        return $validKey;
    }

    private function _startup(){
        // Stop from causing an alarm
        $output = shell_exec("touch /var/motion/startup.file");

        # remove any existing lock file
        $this->_unlock();
    }

    private function _clear($type){
        $message = "";

        // work out which files to clean
        switch($type){

            case "local":
                $message = "Removing local files";
                $output = shell_exec("rm -rf /var/motion/*");
                break;

            case "remote":
                $message = "Removing remote files";
                $output = shell_exec("rm -rf /mnt/Sync/motion/*");
                break;

            case "all":
                $message = "Removing local and remote files";
                $output = shell_exec("rm -rf /var/motion/*");
                $output = shell_exec("rm -rf /mnt/Sync/motion/*");
                break;

        }

        return $message;
    }

    private function _getStatus(){
        $status = "off";

        // Check for motion task
        $output = shell_exec("ps -A | grep motion");
        
        // Work out if motion is running
        if (strpos($output, 'motion') !== false){
            $status = "on";
        }

        return $status;
    }

    private function _lock(){
        // Delete the lock file
        $output = shell_exec("touch /var/motion/lock.file");

        $message = "Lock file created";

        return $message;
    }

    private function _unlock(){
        // Delete the lock file
        $output = shell_exec("rm -f /var/motion/lock.file");

        $message = "Lock file deleted";

        return $message;
    }

}

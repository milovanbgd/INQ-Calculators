<?php
/*
 * This file is part of INQ Calculators.
 *
 * INQ Calculators is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * INQ Calculators is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with INQ Calculators.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Main Controller
 *
 * @copyright Copyright 2010-2011
 * @author Edward Rudd <urkle at outoforder.cc>
 */
define('APP_ROOT', dirname(__FILE__) . '/..');
define('WEB_ROOT', dirname(__FILE__));

$config = include(APP_ROOT . "/config.php");

// Set debugging if enabled
define('DEBUG',!empty($config->debug));

// Load up the class auto loader
require_once(APP_ROOT . "/classes/Init.php");

FB::setEnabled(DEBUG);
Head::setDebug(DEBUG);

Template::addTemplatePath(APP_ROOT . '/templates');
Database::setDSN($config->db->dsn, $config->db->user, $config->db->password);

if ($config->memcache) {
    $memcache = new Memcached();
    $memcache->addServer($config->memcache->host, $config->memcache->port);
    RO_Base::setMemcache($memcache, $config->memcache->expire);
    Head::setMemecache($memcache);
}

if (empty($_GET['PATH_INFO'])) {
    try {
        if ($config->cachehome) {
            header("Pragma: public");
            header("Expires: ".gmdate('D, d M Y H:i:s',time()+$config->cachehome). ' GMT');
        } elseif (DEBUG) {
            header("Expires: ".gmdate('D, d M Y H:i:s',time()-86400). ' GMT');
        }
        // Include the JS/CSS definitions from shared config file
        include '../head.php';

        $tpl = new Template("index.xhtml");
        $datapaths = array();
        if (!empty($config->tools['map'])) {
            $datapaths['mapdata'] = Head::GetVersionLink('images/map/map.xml');
            $datapaths['mapoverlay'] = Head::GetVersionLink('images/map/zones/overlay.json');
        }
        $tpl->js = array(
            'ajaxRoot'=>Util::AjaxBaseURI(),
            'datapaths'=>$datapaths,
        );
        // Load enablement of tools
        $tpl->tools = $config->tools;
        $tpl->title = $config->title;
        $tpl->devheader = $config->devheader;
        $tpl->credits = is_array($config->credits)
                ? $config->credits
                : array(array('name'=>'Anonymous','job'=>'I did something?'));
        // Analytics support
        $tpl->analytics = !empty($config->analytics)
                ? $config->analytics
                : array('enabled'=>false);
        $tpl->echoExecute();
    } catch (Exception $ex) {
        die ((string)$ex);
    }
} else {
    try {
        $path_info = explode("/",trim($_GET['PATH_INFO'],'/'));
        if ($path_info[0]=='ajax' && !empty($path_info[1])
                && preg_match("/^[a-zA-Z]+$/",$path_info[1])) {
            // Process Ajax request
            if (!is_readable(APP_ROOT.'/ajax/'.$path_info[1].'.php')) {
                throw new Exception('Invalid Request');
            }
            include_once(APP_ROOT.'/ajax/'.$path_info[1].'.php');
            $class = 'ajax_'.$path_info[1];

            if (DEBUG) {
                $start = microtime(true);
            }
            $ajax = new $class();
            if ($config->memcache && $ajax->cache) {
                $key = AjaxRequest::genkey();
                $data = $memcache->get($key);
            }
            if (empty($data)) {
                $data = $ajax->request(array_slice($path_info,2));

                if ($config->memcache && $ajax->cache) {
                    $memcache->set($key, $data, $config->memcache->expire);
                }
            }
            if (DEBUG) {
                $stop = microtime(true);
                FB::log(number_format(($stop - $start) * 1000),'Duration');
            }
            header("Content-Type: application/json; charset=UTF-8");
            if ($ajax->cache && $config->ajaxexpires) {
                header("Pragma: public");
                header("Expires: ".gmdate('D, d M Y H:i:s',time()+$config->ajaxexpires). ' GMT');
            } elseif (DEBUG) {
                header("Expires: ".gmdate('D, d M Y H:i:s',time()-86400). ' GMT');
            }
            echo json_encode(array(
                'response'=>'success',
                'data'=>$data,
            ));
        } elseif ($path_info[0]=='help') {
            if ($config->cachehome) {
                header("Pragma: public");
                header("Expires: ".gmdate('D, d M Y H:i:s',time()+$config->cachehome). ' GMT');
            } elseif (DEBUG) {
                header("Expires: ".gmdate('D, d M Y H:i:s',time()-86400). ' GMT');
            }
            $tpl = new Template("help.xhtml");
            $tpl->echoExecute();
        } elseif ($path_info[0]=='license') {
            if ($config->cachehome) {
                header("Pragma: public");
                header("Expires: ".gmdate('D, d M Y H:i:s',time()+$config->cachehome). ' GMT');
            }
            $tpl = new Template("license.xhtml");
            $tpl->license = new FileLoader("http://www.gnu.org/licenses/agpl-3.0-standalone.html");
            $tpl->echoExecute();
        } elseif ($path_info[0]=='css') {
            // Include the JS/CSS definitions from shared config file
            include '../head.php';

            header("Pragma: public");
            header("Content-Type: text/css; charset=UTF-8");
            header("Expires: ".gmdate('D, d M Y H:i:s',  strtotime('+1 year')).' GMT');
            Head::OutputCombined($_GET['PATH_INFO'],$_GET['t']);
        } elseif ($path_info[0]=='js') {
            // Include the JS/CSS definitions from shared config file
            include '../head.php';

            header("Pragma: public");
            header("Content-Type: application/x-javascript; charset=UTF-8");
            header("Expires: ".gmdate('D, d M Y H:i:s',  strtotime('+1 year')).' GMT');
            Head::OutputCombined($_GET['PATH_INFO'],$_GET['t']);
        } else {
            throw new Exception("Invalid Request");
        }
    } catch(PDOException $ex) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(array(
            'response'=>'error',
            'error'=>"Database Error: SQLSTATE:".$ex->getCode(),
        ));
        error_log((string)$ex);
    } catch(Exception $ex) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(array(
            'response'=>'error',
            'error'=>(string)$ex,
        ));
    }
}
?>

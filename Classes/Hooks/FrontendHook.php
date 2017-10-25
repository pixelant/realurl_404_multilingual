<?php
namespace WapplerSystems\Realurl404Multilingual\Hooks;

/***************************************************************
 * Copyright notice
 *
 * (c) 2004-2015 Sven Wappler <typo3YYYY@wapplersystems.de>
 * Based on extension error_404_multilingual
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the
 * script!
 ***************************************************************/

use \TYPO3\CMS\Core\Utility\GeneralUtility;
use \TYPO3\CMS\Core\Utility\HttpUtility;

/**
 *
 */
class FrontendHook
{

    /**
     * show error page directly
     */
    const MODE_NOREDIRECT = '1';

    /**
     * use redirect
     */
    const MODE_REDIRECT = '2';

    /**
     * use first domain
     */
    const MODE_FIRST_DOMAIN = '1';


    /**
     * host
     * @var string current host
     */
    protected $host = '_DEFAULT';


    /**
     * typo3 conf var 404
     * @var array EXTCONF
     */
    protected $config = null;


    /**
     * initialize host and this->typo3_conf_var_404
     */
    public function __construct()
    {
        $this->host = GeneralUtility::getIndpEnv('HTTP_HOST');
        // define config for error_404_multilingual
        $this->config = $this->getConfiguration($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl_404_multilingual'],
            $this->host);
        if (!is_array($this->config)) {
            // set the default
            $this->config = array(
                'errorPage' => '404',
                'unauthorizedPage' => '404',
                'redirects' => array(),
                'stringConversion' => 'none',
            );
        }
    }


    /**
     * @param $params
     * @throws \Exception
     * @param \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $obj
     */
    public function pageErrorHandler(&$params, \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController &$obj)
    {
        if (GeneralUtility::_GP('tx_realurl404multilingual') == '1')
        {
            // we are in a infinite redirect/request loop, which we need to stop
            throw new \Exception('404 page handler stuck in a redirect/request loop. Please check your configration',1474969985);
        }

        if (!empty(GeneralUtility::_GP('tx_realurl404multilingual')) && intval(GeneralUtility::_GP('tx_realurl404multilingual')) == 1) {
            // Again landed here -> break
            header("HTTP/1.0 404 Not Found");
            echo $this->getProvisionally404Page();
            exit();
        }
        $extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['realurl_404_multilingual']);
        $currentUrl = $params['currentUrl'];
        $reasonText = $params['reasonText'];
        $pageAccessFailureReasons = $params['pageAccessFailureReasons'];
        $mode = $extConf['mode'];
        $statusCode = $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFound_handling_statheader'];

        if (isset($pageAccessFailureReasons['fe_group']) && array_shift($pageAccessFailureReasons['fe_group']) != 0) {
            $unauthorizedPage = $this->config['unauthorizedPage'];
            $unauthorizedPage = (!$unauthorizedPage ? '401' : $unauthorizedPage);
            $destinationUrl = $this->getDestinationUrl($currentUrl, $unauthorizedPage, $extConf);
            $destinationUrl .= "?return_url=".urlencode($currentUrl)."&tx_realurl404multilingual=1";
            //$header = "HTTP/1.0 401 Unauthorized";
            $mode = self::MODE_REDIRECT; // force redirect
        } else {
            // define the page name
            $errorpage = $this->config['errorPage'];
            $errorpage = ($errorpage == '' ? '404' : $errorpage);
            $destinationUrl = $this->getDestinationUrl($currentUrl, $errorpage, $extConf);
        }

        switch ($mode) {
            case self::MODE_REDIRECT:
                HttpUtility::redirect($destinationUrl, HttpUtility::HTTP_STATUS_301);
                break;
            default:
                if (self::MODE_FIRST_DOMAIN == $extConf['firstDomain'] && !$this->hostEqualFirstDomain()) {
                    $destinationUrl = $this->getDestinationUrl('', $this->getUri($currentUrl), $extConf, TRUE);
                    HttpUtility::redirect($destinationUrl, HttpUtility::HTTP_STATUS_301);
                } else {
                    $this->getPageAndDisplay($destinationUrl, ($statusCode ? $statusCode : 'HTTP/1.0 404 Not Found'));
                }
                break;
        }
    }


    /**
     * get page and echo it
     * @param $url404 string $url404 404 page url
     * @param $header string http header
     * @return void
     */
    private function getPageAndDisplay($url404, $header)
    {

        header($header);

        // check cache
        /** @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface $cache */
        $cache = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Cache\\CacheManager')->getCache('realurl_404_multilingual');
        $cacheKey = hash('sha1',
            $url404 . '-' . ($GLOBALS['TSFE']->fe_user->user ? $GLOBALS['TSFE']->fe_user->user['uid'] : ''));
        if ($cache->has($cacheKey)) {
            $content = $cache->get($cacheKey);
        }
        if (empty($content)) {
            $content = $this->getUrl($url404);
            if (!empty($content)) {
                switch ($this->config['stringConversion']) {
                    case 'utf8_encode' : {
                        $content = utf8_encode($content);
                        break;
                    }
                    case 'utf8_decode' : {
                        $content = utf8_decode($content);
                        break;
                    }
                }

                $cache->set($cacheKey, $content, array());
            }
        }

        /* print out content of 404 page */
        echo $content;
    }

    /**
     * Returns the URI without leading slashes
     *
     * @param string $url
     * @return string
     */
    function getUri($url = "")
    {
        if (preg_match("/^\/(.*)/i", $url, $reg)) {
            return $reg[1];
        }
        return $url;
    }

    /**
     * Returns the related configuration
     *
     * @param $array array
     * @param $key string
     * @return string
     */
    function getConfiguration($array = array(), $key = '_DEFAULT')
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            $domain_key = $key;
        } else {
            $domain_key = '_DEFAULT';
        }
        if (is_array($array[$domain_key])) {
            return $array[$domain_key];
        } else {
            return $array[$array[$domain_key]];
        }
    }


    /**
     * @param string $currentUrl
     * @param string $suffix
     * @param array $extConf
     * @param bool $extConf
     * @return string
     */
    private function getDestinationUrl($currentUrl = "", $suffix, $extConf, $useFirstDomain = FALSE)
    {
        $host = \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('HTTP_HOST');
        // Get page Uid of start page
        $pageUid = $this->getDomainStartPage($host, GeneralUtility::getIndpEnv('SCRIPT_NAME'), GeneralUtility::getIndpEnv('REQUEST_URI'));
        // Get rootline
        $rootLine = \TYPO3\CMS\Backend\Utility\BackendUtility::BEgetRootLine($pageUid);
        // Get first domain record
        $firstDomain = \TYPO3\CMS\Backend\Utility\BackendUtility::firstDomainRecord($rootLine);
        
        // Change host to first domain record if 'firstDomain' flag is set
        if (self::MODE_FIRST_DOMAIN == $extConf['firstDomain'] && $useFirstDomain) {
            $host = $firstDomain;
        }

        $uri = $this->getUri($currentUrl);
        list($script, $option) = explode("?", $uri);

        // get config for realurl
        $config_realurl = $this->getConfiguration($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'], $host);

        // removes all leading slashes in array
        if (count($this->config['redirects']) > 0) {
            $redirects = array();
            foreach ($this->config['redirects'] as $key => $val) {
                $redirects[$this->getUri($key)] = $this->getUri($val);
            }
            $this->config['redirects'] = $redirects;
        }

        // fallback if typo3_conf_var_404 not an array
        if (!is_array($this->config['redirects'])) {
            $this->config['redirects'] = array();
        }

        // First element will be the host
        $url_array = array();
        $url_array[] = $host;
        $sitePath = \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
        if ($sitePath && strlen(trim($sitePath, '/')) > 0) {
            $url_array[] = trim($sitePath, '/');
        }
        if (is_array($this->config['redirects']) && array_key_exists($uri, $this->config['redirects'])) {
            // There is a redirect defined for this request URI, so the value is taken
            $url_array[] = $this->config['redirects'][$uri];
        } elseif (is_array($this->config['redirects']) && array_key_exists($script,
                $this->config['redirects'])
        ) {
            // There is a redirect defined for this script, so the value is taken
            $url_array[] = $this->config['redirects'][$script];
        } else {
            // Normaly no alternative is defined, so the 404 site will be taken extract the language
            $uriSegments = explode('/', $uri);
            $lang = reset($uriSegments);


            // find language key
            if (is_array($config_realurl['preVars'])) {
                foreach ($config_realurl['preVars'] as $key => $val) {
                    if (isset($config_realurl['preVars'][$key]['GETvar']) && $config_realurl['preVars'][$key]['GETvar'] == "L") {

                        if ($lang != false && is_array($config_realurl['preVars'][$key]['valueMap']) && array_key_exists($lang,
                                $config_realurl['preVars'][$key]['valueMap'])
                        ) {
                            $url_array[] = $lang;
                        } elseif ($config_realurl['preVars'][$key]['valueDefault']) {
                            $url_array[] = $config_realurl['preVars'][$key]['valueDefault'];
                        }
                    }
                }
            }

            $url_array[] = $suffix;
        }

        $useHttps = $_SERVER['HTTPS'];

        return ((!empty($useHttps) && $useHttps !== 'off') ? "https" : "http") . "://" . implode("/", $url_array);
    }

    /**
     * Return TRUE if http_host and first domain record is equal
     *
     * @return boolean
     */
    protected function hostEqualFirstDomain()
    {
        // Get host
        $host = \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('HTTP_HOST');
        // Get page Uid of start page
        $pageUid = $this->getDomainStartPage($host, GeneralUtility::getIndpEnv('SCRIPT_NAME'), GeneralUtility::getIndpEnv('REQUEST_URI'));
        // Get rootline
        $rootLine = \TYPO3\CMS\Backend\Utility\BackendUtility::BEgetRootLine($pageUid);
        // Get first domain record
        $firstDomain = \TYPO3\CMS\Backend\Utility\BackendUtility::firstDomainRecord($rootLine);

        return ($host == $firstDomain) ? TRUE : FALSE;
    }

    /**
     * Will find the page carrying the domain record matching the input domain.
     * Will not exit even if it found domain record instructs to do so.
     *
     * @param string $domain Domain name to search for. Eg. "www.typo3.com". Typical the HTTP_HOST value.
     * @param string $path Path for the current script in domain. Eg. "/somedir/subdir". Typ. supplied by \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('SCRIPT_NAME')
     * @param string $request_uri Request URI: Used to get parameters from if they should be appended. Typ. supplied by \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('REQUEST_URI')
     * @return mixed If found, returns integer with page UID where found. Otherwise blank. Will not exit even if location-header is sent.
     * @see \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::findDomainRecord()
     */
    protected function getDomainStartPage($domain, $path = '', $request_uri = '')
    {
        // Get PageRepository instance
        $pageRepoInstance = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
        
        $domain = explode(':', $domain);
        $domain = strtolower(preg_replace('/\\.$/', '', $domain[0]));
        // Removing extra trailing slashes
        $path = trim(preg_replace('/\\/[^\\/]*$/', '', $path));
        // Appending to domain string
        $domain .= $path;
        $domain = preg_replace('/\\/*$/', '', $domain);

        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('pages.uid', 'pages,sys_domain', 'pages.uid=sys_domain.pid
                        AND sys_domain.hidden=0
                        AND (sys_domain.domainName=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($domain, 'sys_domain') . ' OR sys_domain.domainName=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(($domain . '/'), 'sys_domain') . ') ' . $pageRepo->where_hid_del . $pageRepo->where_groupAccess, '', '', 1);
        $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
        $GLOBALS['TYPO3_DB']->sql_free_result($res);
        if ($row) {
            return $row['uid'];
        }
        return '';
    }



    /**
     * @param string $url
     * @return string
     */
    function getUrl($url = "")
    {

        if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlUse']) {
            // Open url by curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,
                'tx_realurl404multilingual=1' . $this->addFESeesionKeyStringIfLoggedIn());

            //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, GeneralUtility::getIndpEnv('HTTP_USER_AGENT'));


            if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyTunnel']) {
                curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyTunnel']);
            }
            if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer']) {
                curl_setopt($ch, CURLOPT_PROXY, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer']);
            }
            if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass']);
            }
            $urlContent = curl_exec($ch);
            $response = curl_getinfo( $ch );
            
            // in case if redirect
            if ($response['http_code'] == 301 || $response['http_code'] == 302)
            {
                curl_setopt($ch, CURLOPT_URL, $response['redirect_url']);
                $urlContent = curl_exec($ch);
            }

            curl_close($ch);


        } else {
            // Open url by fopen
            set_time_limit(5);

            $opts = array(
                'http' => array(
                    'method' => "GET",
                    'header' => "User-Agent:" . GeneralUtility::getIndpEnv('HTTP_USER_AGENT')
                )
            );
            $context = stream_context_create($opts);

            $urlContent = @file_get_contents($url . '?tx_realurl404multilingual=1' . $this->addFESeesionKeyStringIfLoggedIn(),
                false, $context);

        }

        if (empty($urlContent)) {
            /* display own 404 page, because the real 404 page couldn't be loaded */
            $urlContent = $this->getProvisionally404Page();
        }

        return $urlContent;
    }


    /**
     * add session key if user is logged in
     *
     * @return string session key
     */
    private function addFESeesionKeyStringIfLoggedIn()
    {
        if ($GLOBALS['TSFE']->fe_user->user) {
            return '&FE_SESSION_KEY=' .
            rawurlencode(
                $GLOBALS['TSFE']->fe_user->id .
                '-' .
                md5(
                    $GLOBALS['TSFE']->fe_user->id .
                    '/' .
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
                )
            );
        }
        return '';
    }


    /**
     * TODO: Generate nice 404 page
     * @return string
     */
    private function getProvisionally404Page() {
        return "Sorry, but the 404 page was not found! Please check the path to the 404 page.";
    }


}


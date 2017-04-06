<?php
if (!defined ('TYPO3_MODE')) {
	die ('Access denied.');
}

//fix for error that sometimes appears with main page of translation
//if we have only 4 symbols in request URI and they're finished wth slash
if (strlen($_SERVER['REQUEST_URI']) == 4 && substr($_SERVER['REQUEST_URI'], -1) == '/'){
  //compare each configured language with value of request URI
  foreach ($TYPO3_CONF_VARS['EXTCONF']['realurl']['_DEFAULT']['preVars']['language']['valueMap'] as $lang => $id){
    if (strpos($_SERVER['REQUEST_URI'], '/'.$lang.'/') !== false){
      //$_GET['tx_realurl404multilingual'] = 0;
      $useHook = false;
    } else {
      $useHook = true;
    }
  }
} else {
  $useHook = true;
}
if ($useHook){
  $TYPO3_CONF_VARS['FE']['pageNotFound_handling'] = 'USER_FUNCTION:EXT:'.$_EXTKEY.'/Classes/Hooks/FrontendHook.php:WapplerSystems\\Realurl404Multilingual\\Hooks\\FrontendHook->pageErrorHandler';
}

// Caching the 404 pages - default expire 3600 seconds
if (!is_array($TYPO3_CONF_VARS['SYS']['caching']['cacheConfigurations']['realurl_404_multilingual'])) {
    $TYPO3_CONF_VARS['SYS']['caching']['cacheConfigurations']['realurl_404_multilingual'] = array(
        'frontend' => 'TYPO3\\CMS\\Core\\Cache\\Frontend\\VariableFrontend',
        'backend' => 'TYPO3\\CMS\\Core\\Cache\\Backend\\FileBackend'
    );
}
// Check if request was made from realurl_404_multilingual and session key was pass
$checkIfNeedToDisableIPCheck = function() {
    if (\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('tx_realurl404multilingual') == '1'
        && \TYPO3\CMS\Core\Utility\GeneralUtility::_GP('FE_SESSION_KEY')
        && $_SERVER['SERVER_ADDR'] == \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('REMOTE_ADDR')
    ) {
        $fe_sParts = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode('-', \TYPO3\CMS\Core\Utility\GeneralUtility::_GP('FE_SESSION_KEY'),1);
        // If the session key hash check is OK:
        if (!strcmp(md5(($fe_sParts[0] . '/' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])), $fe_sParts[1])) {
            //disable IP check
            $GLOBALS['TYPO3_CONF_VARS']['FE']['lockIP'] = '0';
        }
    }
};
$checkIfNeedToDisableIPCheck();
unset($checkIfNeedToDisableIPCheck);
?>

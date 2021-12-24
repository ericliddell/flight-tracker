<?php

namespace Vanderbilt\CareerDevLibrary;

use \ExternalModules\ExternalModules;

require_once(dirname(__FILE__)."/preliminary.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$hash = "";
$hashRecordId = "";
$isNewHash = FALSE;
if (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") {
    $currPage = REDCapManagement::sanitize($_GET['page']);
    if (isset($_GET['hash']) && MMAHelper::isValidHash($_GET['hash'])) {
        $proposedHash = REDCapManagement::sanitize($_GET['hash']);
    } else if (isset($_REQUEST['userid']) && MMAHelper::isValidHash($_REQUEST['userid'])) {
        $proposedHash = $_REQUEST['userid'];
    } else {
        $proposedHash = "";
    }
    $isNewHash = ($proposedHash == NEW_HASH_DESIGNATION);
    if (
        !in_array($currPage, ["mentor/intro", "mentor/index", "mentor/createHash"])
        || (
            in_array($currPage, ["mentor/index", "mentor/createHash"])
            && ($proposedHash != NEW_HASH_DESIGNATION)
        )
    ) {
        $records = Download::recordIds($token, $server);
        $proposedRecordId = isset($_GET['menteeRecordId']) ? REDCapManagement::getSanitizedRecord($_GET['menteeRecordId'], $records) : "";
        $res = MMAHelper::validateHash($proposedHash, $token, $server, $proposedRecordId);
        $hashRecordId = $res['record'];
        $hash = $res['hash'];
        if (
            !$hashRecordId
            || !$hash
            || ($hash != $proposedHash)
            || (
                $proposedRecordId
                && ($proposedRecordId != $hashRecordId)
            )
        ) {
            die("Access Denied ");
        }
    }
} else {
    $validREDCapUsers = MMAHelper::getREDCapUsers($pid);
    if (Application::isExternalModule()) {
        $module = Application::getModule();
        $username = $module->getUsername();
        if (MMA_DEBUG && isset($_GET['uid'])) {
            $username = REDCapManagement::sanitize($_GET['uid']);
            $isSuperuser = FALSE;
        } else {
            $isSuperuser = ExternalModules::isSuperUser();
        }
        if (!$module) {
            die("No module.");
        }

        if (
            !$module->hasMentorAgreementRights($pid, $username)
            && !$isSuperuser
            && !in_array($username, $validREDCapUsers)
        ) {
            if (($pid == 101785) && !isset($_GET['test'])) {
                # due to an error by Arnita in sending out the original link
                $thisUrl = Application::link("this");
                $thisUrl = preg_replace("/project_id=101785/", "project_id=117692", $thisUrl);
                header("Location: $thisUrl");
            } else {
                die("Access Denied.");
            }
        }
    } else {
        $username = Application::getUsername();
        if (MMA_DEBUG && isset($_GET['uid'])) {
            $username = REDCapManagement::sanitize($_GET['uid']);
            $isSuperuser = FALSE;
        } else {
            $isSuperuser = defined('SUPER_USER') && (SUPER_USER == '1');
        }

        if (
            !MMAHelper::hasMentorAgreementRightsForPlugin($pid, $username)
            && !$isSuperuser
            && !in_array($username, $validREDCapUsers)
        ) {
            die("Access Denied.");
        }
    }
}


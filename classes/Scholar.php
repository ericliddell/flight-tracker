<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__ . '/ClassLoader.php');

define("SOURCETYPE_FIELD", "additional_source_types");
define("SHOW_DEBUG_FOR_INSTITUTIONS", FALSE);

class Scholar {
	public function __construct($token, $server, $metadata = array(), $pid = "") {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		if (empty($metadata)) {
			$this->metadata = Download::metadata($token, $server);
		} else {
			$this->metadata = $metadata;
		}
	}

	# for identifier_left_job_category
	public static function isOutsideAcademe($jobCategory) {
		$outside = array(3, 4, 6);
		if (in_array($jobCategory, $outside)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function getRepeatingForms($pid) {
		return REDCapManagement::getRepeatingForms($pid);
	}

	public static function isDependentOnAcademia($field) {
		$dependent = array("summary_current_division", "summary_current_rank", "summary_current_tenure", );
		if (in_array($field, $dependent)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function addSourceType($module, $code, $sourceType, $pid) {
		if ($module) {
			$data = $module->getProjectSetting(SOURCETYPE_FIELD, $pid);
			if (!$data) {
				$data = array();
			}
			if (!isset($data[$sourceType])) {
				$data[$sourceType] = array();
			}
			array_push($data[$sourceType], $code);
			$module->setProjectSetting(SOURCETYPE_FIELD, $data, $pid);
			return TRUE;
		}
		return FALSE;
	}

	public static function getAdditionalSourceTypes($module, $sourceType, $pid) {
		if ($module) { 
			$data = $module->getProjectSetting(SOURCETYPE_FIELD, $pid);
			if (!$data || !isset($data[$sourceType])) {
				return array();
			}
			return $data[$sourceType];
		}
		return array();
	}

	public static function getChoices($metadata = array()) {
		if (!empty($metadata)) {
			self::$choices = REDCapManagement::getChoices($metadata);
		}
		return self::$choices;
	}

	public static function getKLength($type) {
		// Application::log("Getting K Length for $type");
		if ($type == "Internal") {
			return Application::getInternalKLength();
		} else if ($type == 1) {
			return Application::getInternalKLength();
		} else if ($type == "K12/KL2") {
			return Application::getK12KL2Length();
		} else if ($type == 2) {
			return Application::getK12KL2Length();
		} else if ($type == "External") {
			return Application::getIndividualKLength();
		} else if (($type == 3) || ($type == 4)) {
			return Application::getIndividualKLength();
		}
		return 0;
	}

	public function setGrants($grants) {
		$this->grants = $grants;
	}

	public function getORCID() {
		$result = $this->getORCIDResult($this->rows);
		return $result->getValue();
	}

	public function getORCIDWithLink() {
		$orcid = $this->getORCID();
		return Links::makeLink("https://orcid.org/".$orcid, $orcid);
	}

	public function getORCIDResult($rows) {
		$row = self::getNormativeRow($rows);

		# by default use identifier; if not specified, get result through default order
		if ($row['identifier_orcid']) {
			$result = new Result($row['identifier_orcid'], "", "", "", $this->pid);
		} else {
			$vars = self::getDefaultOrder("identifier_orcid");
            $vars = $this->getOrder($vars, "identifier_orcid");
			$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		}
		$value = $result->getValue();
		$searchTerm = "/^https:\/\/orcid.org\//";
		if (preg_match($searchTerm, $value)) {
			# they provided URL instead of number
			$result->setValue(preg_replace($searchTerm, "", $value));
		}
		return $result;
	}

    public function lookupUserid($rows) {
        return $this->getUserid($rows, "identifier_userid");
    }

    public function lookupVUNet($rows) {
        return $this->getUserid($rows, "identifier_vunet");
    }

    private function getUserid($rows, $field) {
        $row = self::getNormativeRow($rows);
	    if ($row[$field]) {
            return new Result($row[$field], "", "", "", $this->pid);
        }
        $vars = self::getDefaultOrder($field);
        $vars = $this->getOrder($vars, $field);
        $result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
        if ($result->getSource() == "ldap") {
            return $this->getLDAPResult($rows, "ldap_uid", $result);
        }
        return $result;
    }

    public function lookupEmail($rows) {
	    if ($email = $this->getEmail()) {
	        return new Result($email, "");
        }
        $vars = self::getDefaultOrder("identifier_email");
        $vars = $this->getOrder($vars, "identifier_email");
        $result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
        if ($result->getSource() == "ldap") {
            return $this->getLDAPResult($rows, "ldap_mail", $result);
        }
        return $result;
    }

    private function getLDAPResult($rows, $field, $priorResult) {
        $numRows = REDCapManagement::getNumberOfRows($rows, "ldap");
        if ($numRows == 1) {
            return $priorResult;
        } else if ($numRows == 2) {
            $validLDAPDomains = ["vumc.org", "vanderbilt.edu"];
            $numExpected = 2;

            $emails = [];
            foreach ($rows as $row) {
                if (($row['redcap_repeat_instrument'] == "ldap") && !in_array($row['ldap_vanderbiltpersonjobname'], self::$skipJobs)) {
                    $emails[$row[$field]] = explode("@", strtolower($row["ldap_mail"]));
                }
            }
            $keys = array_keys($emails);
            if (count($emails) == 0) {
                return new Result("", "");
            } else if (count($emails) == 1) {
                return new Result($keys[0], "ldap", "Computer-Generated", "", $this->pid);
            } else if (count($emails) == $numExpected) {
                if ((count($emails[$keys[0]]) == $numExpected) && (count($emails[$keys[1]]) == $numExpected)) {
                    if ($emails[$keys[0]][0] == $emails[$keys[1]][0]) {
                        if (in_array($emails[$keys[0]][1], $validLDAPDomains) && in_array($emails[$keys[1]][1], $validLDAPDomains)) {
                            # same email address; different domain; all domains in $validLDAPDomains
                            if ($emails[$keys[0]][1] == "vumc.org") {
                                return new Result($keys[0], "ldap", "Computer-Generated", "", $this->pid);
                            } else {
                                return new Result($keys[1], "ldap", "Computer-Generated", "", $this->pid);
                            }
                        } else {
                            throw new \Exception("Invalid domain: ".$emails[$keys[0]][1]." and ".$emails[$keys[1]][1]);
                        }
                    } else {
                        # do not throw exception because this could still be a valid pull; treat as if there were 3+ results
                        Application::log("Different emails: ".$emails[$keys[0]][0]." and ".$emails[$keys[1]][0]);
                        return new Result("", "");
                    }
                } else {
                    throw new \Exception("For some reason, I found parts (".count($emails[$keys[0]])." and ".count($emails[$keys[1]]).") when I was expecting $numExpected!");
                }
            } else {
                throw new \Exception("For some reason, I found ".count($emails)." emails when I was expecting $numExpected!");
            }
        }
        else {      // == 0 or > 2
            # cannot differentiate between more than one row
            return new Result("", "");
        }
    }

    # lookupEmail calculates the email; this simply returns the value that has already been saved
	public function getEmail() {
		$row = self::getNormativeRow($this->rows);
		return $row['identifier_email'];
	}

	public function getResourcesUsed() {
		$resources = array();
		$choices = self::getChoices($this->metadata);
		foreach ($this->rows as $row) {
			$date = "";
			$resource = "";
			foreach ($row as $field => $value) {
				if ($value) {
					if ($field == "resources_date") {
						$date = $value;
					} else if ($field == "resources_resource") {
						$resource = $choices['resources_resource'][$value];
					}
				}
			}
			if ($resource) {
				$title = $resource;
				if ($date) {
					$title .= " (".$date.")";
				}
				array_push($resources, $title);
			}
		}
		return $resources;
	}

	public static function nameInList($name, $list) {
		$nameAry = NameMatcher::splitName($name, 2);
		foreach ($list as $item) {
		    $itemNameAry = NameMatcher::splitName($item, 2);
		    if (NameMatcher::matchName($nameAry[0], $nameAry[1], $itemNameAry[0], $itemNameAry[1])) {
		        return TRUE;
            }
        }
		return FALSE;
	}

    private function getStudySection1($rows) {
        return $this->getStudySection($rows, 1);
    }

    private function getStudySection2($rows) {
        return $this->getStudySection($rows, 2);
    }

    private function getStudySection3($rows) {
        return $this->getStudySection($rows, 3);
    }

    private function getStudySection4($rows) {
        return $this->getStudySection($rows, 4);
    }

    private function getStudySectionOther1($rows) {
        return $this->getStudySectionOther($rows, 1);
    }

    private function getStudySectionOther2($rows) {
        return $this->getStudySectionOther($rows, 2);
    }

    private function getStudySectionOther3($rows) {
        return $this->getStudySectionOther($rows, 3);
    }

    private function getStudySectionOther4($rows) {
        return $this->getStudySectionOther($rows, 4);
    }

    private function getStudySection($rows, $i) {
	    $field = "summary_study_section_name_".$i;
        return $this->getGenericValueForField($rows, $field);
    }

    private function getStudySectionOther($rows, $i) {
        $field = "summary_other_standing_" . $i;
        return $this->getGenericValueForField($rows, $field);
    }

    private function getGenericValueForField($rows, $field, $byLatest = FALSE, $showDebug = FALSE) {
        $vars = self::getDefaultOrder($field);
        $vars = $this->getOrder($vars, $field);
        $result = self::searchRowsForVars($rows, $vars, $byLatest, $this->pid, $showDebug);
        return $result;
    }

    private function getEcommonsId($rows) {
        return $this->getGenericValueForField($rows, "identifier_ecommons_id");
    }

	private function getMentorUserid($rows) {
		$mentorResult = $this->getMentorText($rows);
		$mentorField = $mentorResult->getField();
		$mentorUseridField = $mentorField."_userid";
		foreach ($rows as $row) {
			if ($row[$mentorUseridField]) {
				$r = new Result($row[$mentorUseridField], $mentorResult->getSource(), "", "", $this->pid);
				$r->setField($mentorUseridField);
				return $r;
			}
		}

		$uids = [];
		if ($mentorResult->getValue()) {
            if (Application::isVanderbilt()) {
                try {
                    list($firstName, $lastName) = NameMatcher::splitName($mentorResult->getValue());
                    $firstName = NameMatcher::eliminateInitials($firstName);
                    $key = LDAP::getNameAssociations($firstName, $lastName);
                    $info = LDAP::getLDAPByMultiple(array_keys($key), array_values($key));
                    if ($info) {
                        $allUIDs = LDAP::findField($info, "uid");
                        $jobs = LDAP::findField($info, "vanderbiltpersonjobname");
                        if (count($allUIDs) == 1) {
                            $uids = $allUIDs;
                        } else {
                            for ($i = 0; ($i < count($allUIDs)) && ($i < count($jobs)); $i++) {
                                if (!in_array($jobs[$i], self::$skipJobs)) {
                                    $uids[] = $allUIDs[$i];
                                }
                            }
                        }
                    }
                    $source = "ldap";
                    $sourceName = "LDAP";
                } catch(\Exception $e) {
                    Application::log("LDAP mentor lookup ERROR: ".$e->getMessage(), $this->pid);
                }
            }
            if (empty($uids)) {
                list($firstName, $lastName) = NameMatcher::splitName($mentorResult->getValue());
                $firstName = NameMatcher::eliminateInitials($firstName);
                $uids = self::getREDCapUseridsForName($firstName, $lastName);
                $sourceName = "REDCap";
                $source = "";
            }
        }
		if (!empty($uids)) {
            if (count($uids) == 1) {
                return new Result($uids[0], $source, "Computer-Generated", "", $this->pid);
            } else if (count($uids) > 1) {
                Application::log("Warning: Lookup $sourceName userids for $firstName $lastName generated multiple: ".implode(", ", $uids), $this->pid);
                return new Result(implode(", ", $uids), $source, "Computer-Generated", "", $this->pid);
            } else {
                Application::log("Lookup $sourceName userids for $firstName $lastName generated no results.", $this->pid);
            }
        }
		return new Result("", "");
	}

	public static function getREDCapUseridsForName($firstName, $lastName) {
	    if ($firstName && $lastName) {
	        #MySQL performs case-insensitive searches
	        $sql = "SELECT username FROM redcap_user_information WHERE user_firstname = '".db_real_escape_string($firstName)."' AND user_lastname = '".db_real_escape_string($lastName)."'";
            $q = db_query($sql);
            $userids = [];
            while ($row = db_fetch_assoc($q)) {
                $userid = $row['username'];
                if (!in_array($userid, $userids)) {
                    $userids[] = $userid;
                }
            }
            return $userids;
        }
	    return [];
    }

	private function getMentorText($rows) {
        return $this->getGenericValueForField($rows, "summary_mentor");
	}

	public function getAllMentors() {
		$mentorFields = $this->getMentorFields();
		$mentors = array();
		foreach ($this->rows as $row) {
			foreach ($row as $field => $value) {
			    if (preg_match("/\s*[\/;]\s*/", $value)) {
                    $values = preg_split("/\s*[\/;]\s*/", $value);
                } else {
                    $values = [$value];
                }
			    foreach ($values as $v) {
                    if ($v && !is_numeric($v) && in_array($field, $mentorFields) && !self::nameInList($v, $mentors)) {
                        $mentors[] = $v;
                    }
                }
			}
		}
		return $mentors;
	}

	public function getMentorFields() {
		$fields = [];
		$skipRegex = [
		    "/_vunet$/",
            "/_source$/",
            "/_sourcetype$/",
            "/_userid$/",
            "/_time$/",
            "/_user$/",
        ];

		foreach ($this->metadata as $row) {
			if (preg_match("/mentor/", $row['field_name'])) {
				$skip = FALSE;
				foreach ($skipRegex as $regex) {
					if (preg_match($regex, $row['field_name'])) {
						$skip = TRUE;
						break;
					}
				}
				if (!$skip) {
					array_push($fields, $row['field_name']);
				}
			}
		}
		return $fields;
	}

	public function getEmploymentStatus() {
		$left = $this->getWhenLeftInstitution($this->rows);
		if ($left->getValue()) {
			return $left->getValue();
		}
		$row = self::getNormativeRow($this->rows);
		if ($row['identifier_institution']) {
			if ($row['identifier_institution'] && in_array($row['identifier_institution'], Application::getInstitutions())) {
				return "Employed at ".$row['identifier_institution'];
			} else if ($row['identifier_institution'] && ($row['identifier_institution'] != Application::getUnknown())) {
				$institution = " for ".$row['identifier_institution'];
				$date = "";
				if ($row['identifier_left_date']) {
					$date = " on ".$row['identifier_left_date'];
				}
				return "Left ".Application::getInstitution().$institution.$date;
			}
		} else if ($row['identifier_left_date']) {
			$date = " on ".$row['identifier_left_date'];
			return "Left ".Application::getInstitution().$date;
		}
		return "Employed at ".Application::getInstitution();
	}

	public function getDegreesText() {
        $choices = self::getChoices($this->metadata);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        $degreeCheckboxField = "summary_all_degrees";
        if (in_array($degreeCheckboxField, $metadataFields)) {
            $checkedDegreesCoded = REDCapManagement::findField($this->rows, $this->recordId, $degreeCheckboxField);
            if (!is_array($checkedDegreesCoded)) {
                if ($checkedDegreesCoded) {
                    $checkedDegreesCoded = [$checkedDegreesCoded];
                } else {
                    $checkedDegreesCoded = [];
                }
            }
            $allDegrees = [];
            foreach ($checkedDegreesCoded as $checkedCode) {
                $allDegrees[] = $choices[$degreeCheckboxField][$checkedCode];
            }
            if (!empty($allDegrees)) {
                return implode(", ", $allDegrees);
            } else {
                return "None";
            }
        } else {
            $degreesResult = $this->getDegrees($this->rows);
            $degrees = $degreesResult->getValue();
            return $choices["summary_degrees"][$degrees];
        }
	}

    public function getPrimaryDepartmentText() {
        $deptResult = $this->getPrimaryDepartment($this->rows);
        $dept = $deptResult->getValue();
        $choices = self::getChoices($this->metadata);
        return $choices["summary_primary_dept"][$dept];
    }

    public function getCurrentDivisionText() {
        $divResult = $this->getCurrentDivision($this->rows);
        $div = $divResult->getValue();
        return $div;
    }

    public function getImageBase64() {
	    $field = "identifier_picture";
	    $edocId = REDCapManagement::findField($this->rows, $this->recordId, $field);
	    if ($edocId) {
            $filename = REDCapManagement::getFileNameForEdoc($edocId);
            if (file_exists($filename)) {
                $binary = file_get_contents($filename);
                $base64 = base64_encode($binary);
                $mime = mime_content_type($filename);
                $header = "data:$mime;charset=utf-8;base64, ";
                return $header.$base64;
            }
        }
	    return FALSE;
    }

    public function getName($type = "full") {
		$nameAry = $this->getNameAry();

		if ($type == "first") {
			return $nameAry['identifier_first_name'];
		} else if ($type == "last") {
			return $nameAry['identifier_last_name'];
		} else if ($type == "full") {
			return $nameAry['identifier_first_name']." ".$nameAry['identifier_last_name'];
		}
		return "";
	}

	public function getNameAry() {
		if ($this->name) {
			return $this->name;
		}
		return array();
	}

	public function setRows($rows) {
		$this->rows = $rows;
		foreach ($rows as $row) {
			if (isset($row['record_id'])) {
				$this->recordId = $row['record_id'];
			}
			if (($row['redcap_repeat_instance'] == "") && ($row['redcap_repeat_instrument'] == "")) {
				$this->name = array();
				$nameFields = array("identifier_first_name", "identifier_last_name");
				foreach ($nameFields as $field) {
					$this->name[$field] = $row[$field];
				}
			}
		}
		if (!$this->grants) {
			$grants = new Grants($this->token, $this->server, $this->metadata);
			$grants->setRows($this->rows);
			$this->setGrants($grants);
		}
	}

	public function downloadAndSetup($recordId) {
		$rows = Download::records($this->token, $this->server, array($recordId));
		$this->setRows($rows);
	}

	public static function hasDemographics($rows, $metadata) {
		$fields = self::getDemographicFields($metadata);
		$has = array();
		foreach ($fields as $field => $func) {
			$has[$field] = FALSE;
		}
		foreach ($fields as $field => $func) {
			foreach ($rows as $row) {
				if (isset($row[$field])) {
					$has[$field] = TRUE;
				}
			}
		}
		foreach ($has as $field => $b) {
			if (!$b) {
				return FALSE;
			}
		}
		return TRUE;
	}

	public function process() {
		if ((count($this->rows) == 1) && self::hasDemographics($this->rows, $this->metadata) && ($this->rows[0]['redcap_repeat_instrument'] == "")) {
			$this->loadDemographics();
		} else {
			$this->processDemographics();
		}
		if (!isset($this->grants)) {
			$this->initGrants();
		}
		$this->getMetaVariables();
	}

	private function setupTests() {
		$records = Download::recordIds($this->token, $this->server);
		$n = rand(0, count($records) - 1);
		$record = $records[$n];
		$this->downloadAndSetup($record);
	}

	private static function isValidValue($metadataFields, $choices, $field, $value) {
	    if (preg_match("/___/", $field)) {
            if (!in_array($value, [0, 1, ""])) {
                return FALSE;
            }
            $a = preg_split("/___/", $field);
            if (count($a) != 2) {
                return FALSE;
            }
            $field = $a[0];
            $value = $a[1];
        }
        if (in_array($field, $metadataFields)) {
			if (isset($choices[$field])) {
				if (isset($choices[$field][$value])) {
					return TRUE;
				} else {
					return FALSE;
				}
			} else {
				return TRUE;
			}
		} else {
			return FALSE;
		}
	}

	public function makeUploadRow() {
		$metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
		$choices = self::getChoices($this->metadata);
		$uploadRow = array(
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"summary_last_calculated" => date("Y-m-d H:i"),
					);
		foreach ($this->name as $var => $value) {
			if (self::isValidValue($metadataFields, $choices, $var, $value)) {
				$uploadRow[$var] = $value;
			}
		}
		foreach ($this->demographics as $var => $value) {
			if (self::isValidValue($metadataFields, $choices, $var, $value)) {
				$uploadRow[$var] = $value;
			}
		}
		foreach ($this->metaVariables as $var => $value) {
			if (self::isValidValue($metadataFields, $choices, $var, $value)) {
				$uploadRow[$var] = $value;
			}
		}

		$grantUpload = $this->grants->makeUploadRow();
		foreach ($grantUpload as $var => $value) {
			if (!isset($uploadRow[$var]) && self::isValidValue($metadataFields, $choices, $var, $value)) {
				$uploadRow[$var] = $value;
			}
		}

		$uploadRow['summary_complete'] = '2';

		return $uploadRow;
	}

	public function all_functions_test($tester) {
		$this->setupTests();
		$tester->tag("Record ".$this->recordId);
		$tester->assertNotNull($this->recordId);

		$tester->tag("Record ".$this->recordId.": token A");
		$tester->assertNotNull($this->token);
		$tester->tag("Record ".$this->recordId.": token B");
		$tester->assertNotBlank($this->token);

		$tester->tag("Record ".$this->recordId.": server A");
		$tester->assertNotNull($this->server);
		$tester->tag("Record ".$this->recordId.": server B");
		$tester->assertNotBlank($this->server);

		$this->process();
		$tester->tag("demographics filled");
		$tester->assertNotEqual(0, count($this->demographics));
		$tester->tag("meta variables filled");
		$tester->assertNotEqual(0, count($this->metaVariables));
		$tester->tag("name filled");
		$tester->assertNotEqual(0, count($this->name));

		$uploadRow = $this->makeUploadRow();
		$grantUpload = $this->grants->makeUploadRow();
		$tester->tag("Record ".$this->recordId.": Number of components");
		$tester->assertEqual(count($uploadRow), count($this->name) + count($this->demographics) + count($this->metaVariables) + (count($grantUpload) - 3) + 4);
		$metadata = Download::metadata($this->token, $this->server);
		$indexedMetadata = array();
		foreach ($metadata as $row) {
			$indexedMetadata[$row['field_name']] = $row;
		}

		$skip = array("record_id", "redcap_repeat_instance", "redcap_repeat_instrument");
		foreach ($uploadRow as $var => $value) {
			if (!in_array($var, $skip)) {
				$tester->tag("$var is present in upload row; is it present in metadata?");
				$tester->assertNotNull($metadata[$var]);
			}
		}
	}

	public function upload() {
		$uploadRow = $this->makeUploadRow();
		return Upload::oneRow($uploadRow, $this->token, $this->server);
	}

	private function isLastKExternal() {
		if (isset($this->metaVariables['summary_last_any_k'])
			&& isset($this->metaVariables['summary_last_external_k'])
			&& $this->metaVariables['summary_last_external_k']
			&& $this->metaVariables['summary_last_any_k']) {
			if ($this->metaVariables['summary_last_any_k'] == $this->metaVariables['summary_last_external_k']) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private function isLastKK12KL2() {
		$k12kl2Type = 2;
		$ksInside = array(1, 2, 3, 4);
		if ($this->isLastKExternal()) {
			return FALSE;
		} else {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			$lastKType = FALSE;
			foreach ($grantAry as $grant) {
				$type = $grant->getVariable("type");
				if (in_array($type, $ksInside)) {
					$lastKType = $type;
				}
			}
			return ($lastKType == $k12kl2Type);
		}
	}

	private static function calculateKLengthInSeconds($type = "Internal") {
		if ($type == "Internal") {
			return Application::getInternalKLength() * 365 * 24 * 3600;
		} else if (($type == "K12KL2") || ($type == "K12/KL2")) {
			return Application::getK12KL2Length() * 365 * 24 * 3600;
		} else if ($type == "External") {
			return Application::getIndividualKLength() * 365 * 24 * 3600;
		}
		return 0;
	}

	private function hasK() {
		if ($this->grants) {
			$ks = array("Internal K", "K12/KL2", "Individual K", "External K");
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if (in_array($grant->getVariable("type"), $ks)) {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasK99R00() {
		if ($this->grants) {
			foreach ($this->grants->getGrants("native") as $grant) {
				if ($grant->getVariable("type") == "K99/R00") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasR01() {
		if ($this->grants) {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if ($grant->getVariable("type") == "R01") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasR01OrEquiv() {
		if ($this->grants) {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if ($grant->getVariable("type") == "R01") {
					return TRUE;
				}
				if ($grant->getVariable("type") == "R01 Equivalent") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasMetaVariables() {
		return (count($this->metaVariables) > 0) ? TRUE : FALSE;
	}

	public function getLastKDate() {
        $ks = [1, 2, 3, 4];
        $lastK = "";
        foreach ($this->rows as $row) {
            for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                if (in_array($row['summary_award_type_' . $i], $ks) && $row['summary_award_date_' . $i]) {
                    $lastK = $row['summary_award_date_'.$i];
                }
            }
        }
        return $lastK;
    }

	public function startedKOnOrAfterTs($ts) {
	    $lastK = $this->getLastKDate();
	    if ($lastK) {
            $currTs = strtotime($lastK);
            return ($currTs >= $ts);
        }
	    return FALSE;
    }

	# requires that identifier_institution and identifier_left_date be downloaded into rows
	public function hasLeftInstitution() {
        foreach ($this->rows as $row) {
            $atOtherInstitution = $row['identifier_institution'] && !in_array($row['identifier_institution'], Application::getInstitutions());
            if ($atOtherInstitution || $row['identifier_left_date']) {
                return TRUE;
            }
        }
        return FALSE;
    }

	public function onK($r01Only = FALSE, $makeKLengthLongest = FALSE) {
	    if ($makeKLengthLongest) {
            $kLengthInSeconds = self::calculateMaxKLengthInSeconds();
        }
        if (!$this->hasMetaVariables()) {
            $this->getMetaVariables();
        }
        if ($r01Only) {
            if ($this->hasR01()) {
                return FALSE;
            }
        } else {
            if ($this->hasR01OrEquiv()) {
                return FALSE;
            }
        }
        if ($this->hasK()) {
            $lastTime = strtotime($this->metaVariables['summary_last_any_k']);
            if ($this->isLastKExternal()) {
                $kLength = $makeKLengthLongest ? $kLengthInSeconds : self::calculateKLengthInSeconds("External");
                if (time() > $lastTime + $kLength) {
                    return FALSE;
                } else {
                    return TRUE;
                }
            } else {
                if ($makeKLengthLongest) {
                    $kLength = $kLengthInSeconds;
                } else if ($this->isLastKK12KL2()) {
                    $kLength = self::calculateKLengthInSeconds("K12/KL2");
                } else {
                    $kLength = self::calculateKLengthInSeconds("Internal");
                }
                if (time() > $lastTime + $kLength) {
                    return FALSE;
                } else {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    private static function calculateMaxKLengthInSeconds() {
        $possibleKLengths = [
            self::calculateKLengthInSeconds("External"),
            self::calculateKLengthInSeconds("K12/KL2"),
            self::calculateKLengthInSeconds("Internal"),
        ];
        return max($possibleKLengths);
    }

	public function isConverted($autoCalculate = TRUE, $makeKLengthLongest = FALSE) {
        if ($makeKLengthLongest) {
            $kLengthInSeconds = self::calculateMaxKLengthInSeconds();
        }
		if ($this->hasMetaVariables()) {
			if ($this->hasK99R00()) {
				return "Not Eligible";
			} else if ($this->hasK()) {
				if ($this->hasR01OrEquiv()) {
					$lastTime = strtotime($this->metaVariables['summary_last_any_k']);
					$rTime = strtotime($this->metaVariables['summary_first_r01_or_equiv']);
					if ($this->isLastKExternal()) {
					    $kLength = $makeKLengthLongest ? $kLengthInSeconds : self::calculateKLengthInSeconds("External");
						if ($rTime > $lastTime + $kLength) {
							return "Converted while not on K";
						} else {
							return "Converted while on K";
						}
					} else {
					    if ($makeKLengthLongest) {
					        $kLength = $kLengthInSeconds;
                        } else if ($this->isLastKK12KL2()) {
							$kLength = self::calculateKLengthInSeconds("K12/KL2");
						} else {
							$kLength = self::calculateKLengthInSeconds("Internal");
						}
						if ($rTime > $lastTime + $kLength) {
							return "Converted while not on K";
						} else {
							return "Converted while on K";
						}
					}
				} else {
					if ($this->onK()) {
					    return "Not Eligible";
                    } else {
					    return "Not Converted";
                    }
				}
			} else {
				return "Not Eligible";
			}
		} else if ($autoCalculate) {
			$this->getMetaVariables();
			return $this->isConverted(FALSE);
		} else {
			if ($this->hasK()) {
				return "Not Converted";
			} else {
				return "Not Eligible";
			}
		}
	}

	private function getMetaVariables() {
		if ($this->grants) {
			$this->metaVariables = $this->grants->getSummaryVariables($this->rows);
		} else {
			$this->metaVariables = array();
		}
	}

    private function getTimestamps($rows, $func) {
        $timestamps = [];
	    if (method_exists($this, $func)) {
            $dates = $this->$func($rows, FALSE);
            foreach ($dates as $date) {
                $ts = strtotime($date);
                if ($ts) {
                    $timestamps[] = $ts;
                }
            }
        }
        return $timestamps;
    }

    public function getPubDates($rows, $withLink = FALSE) {
	    $pubs = new Publications($this->token, $this->server, $this->metadata);
	    $pubs->setRows($rows);
	    $dates = [];
	    $event_id = Application::getSetting("event_id", $this->pid);
	    foreach ($pubs->getCitations("Included") as $citation) {
	        $ts = $citation->getTimestamp();
	        if ($ts) {
	            if ($withLink) {
	                $link = Links::makePublicationsLink($this->pid, $this->recordId, $event_id, "See Publication", $citation->getInstance(), TRUE);
                    $dates[$link] = date("Y-m-d", $ts);
                } else {
                    $dates[] = date("Y-m-d", $ts);
                }
            }
        }
	    return $dates;
    }

    public function getGrantDates($rows, $withLink = FALSE) {
        $grants = new Grants($this->token, $this->server, $this->metadata);
        $grants->setRows($rows);
        $grants->compileGrants("Conversion");
        $dates = [];
        $event_id = Application::getSetting("event_id", $this->pid);
        foreach ([$grants->getGrants("all"), $grants->getGrants("submissions")] as $grantList) {
            foreach ($grantList as $grant) {
                $startDate = $grant->getVariable("start");
                $endDate = $grant->getVariable("end");
                foreach ([$endDate, $startDate] as $date) {
                    if ($date) {
                        if ($withLink) {
                            $link = $grant->getVariable("link");
                            $dates[$link] = $date;
                        } else {
                            $dates[] = $date;
                        }
                        break;
                    }
                }
            }
        }
        return $dates;
    }

    private function calculateFirstGrantActivity($rows) {
        return $this->calculateActivity($rows, "first", "Grants");
    }

    private function calculateLastGrantActivity($rows) {
        return $this->calculateActivity($rows, "last", "Grants");
    }

    private function calculateFirstPubActivity($rows) {
        return $this->calculateActivity($rows, "first", "Publications");
    }

    private function calculateLastPubActivity($rows) {
        return $this->calculateActivity($rows, "last", "Publications");
    }

    private function calculateActivity($rows, $lastOrFirst, $entity) {
	    if ($entity == "Grants") {
            $timestamps = $this->getTimestamps($rows, "getGrantDates");
        } else if ($entity == "Publications") {
            $timestamps = $this->getTimestamps($rows, "getPubDates");
        } else {
	        $timestamps = [];
        }
	    if (count($timestamps) > 0) {
	        if ($lastOrFirst == "last") {
                rsort($timestamps);
            } else if ($lastOrFirst == "first") {
	            sort($timestamps);
            }
	        $date = date("Y-m-d", $timestamps[0]);
	        Application::log("calculateActivity {$this->recordId}: $lastOrFirst $entity returning $date", $this->pid);
	        return new Result($date, "");
        }
	    return new Result("", "");
    }

    private function calculateCOEUSName($rows) {
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "coeus") {
				new Result($row['coeus_person_name'], "", "", "", $this->pid);
			}
		}
		return new Result("", "");
	}

	private function getSurvey($rows) {
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "scholars") {
				if ($row['check_name_first'] || $row['check_name_last']) {
					return new Result(1, "", "", "", $this->pid); // YES
				}
			}
		}
		return new Result(0, "", "", "", $this->pid); // NO
	}

	private static function getNormativeRow($rows) {
	    return REDCapManagement::getNormativeRow($rows);
	}

	private static function getResultForPrefices($prefices, $row, $suffix, $pid = "") {
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix.$suffix;
			if (isset($row[$variable]) &&
				(preg_match("/".strtolower(Application::getInstitution())."/", strtolower($row[$variable])) || preg_match("/vumc/", strtolower($row[$variable]))) &&
				isset($row[$variable_date]) &&
				($row[$variable_date] != "")) {

				return new Result($row[$variable_date], $type, "", "", $pid);
			}
		}
		return new Result("", "");
	}


	# returns a Result for to where (whither) they left current institution
	# returns blank if at current institution
    private function getAllOtherInstitutionsAsList($rows) {
        $institutions = $this->getAllOtherInstitutions($rows);
        $institutions = REDCapManagement::dedup1DArray($institutions);
        return new Result(implode(", ", $institutions), "");
    }

    private function getAllOtherInstitutions($rows) {
	    $showDebug = SHOW_DEBUG_FOR_INSTITUTIONS;
        $currentProjectInstitutions = Application::getInstitutions();
        for ($i = 0; $i < count($currentProjectInstitutions); $i++) {
            $currentProjectInstitutions[$i] = trim(strtolower($currentProjectInstitutions[$i]));
        }

        $splitterRegex = "/\s*[,;\/]\s*/";
        $choices = self::getChoices($this->metadata);

	    $priorInstitutionList = REDCapManagement::findField($rows, $this->recordId,"identifier_institution");
        $seenInstitutions = [];
        $seenInstitutionsInLowerCase = [];
	    if ($priorInstitutionList) {
            $candidates = preg_split($splitterRegex, $priorInstitutionList);
            foreach  ($candidates as $institution) {
                if (!in_array(strtolower($institution), $currentProjectInstitutions)) {
                    # filter out current institutions as these are searched by default
                    $seenInstitutions[] = $institution;
                    $seenInstitutionsInLowerCase[] = strtolower($institution);
                }
            }
        }

        $defaultOrder = self::getDefaultOrder("identifier_institution");
        $vars = $this->getOrder($defaultOrder, "identifier_institution");
        $fields = array_unique(array_merge(
            array_keys($vars),
            self::getTrainingInstitutionFields(),
            self::getPriorAppointmentInstitutionFields()
        ));
        foreach ($fields as $field) {
            $values = REDCapManagement::findAllFields($rows, $this->recordId, $field);
            if ($showDebug) {
                Application::log("In getAllOtherInstitutions for $field, found ".json_encode($values));
            }
            foreach ($values as $value) {
                if (isset($choices[$field]) && isset($choices[$field][$value])) {
                    $institutions = [$choices[$field][$value]];
                } else {
                    $institutions = preg_split($splitterRegex, $value);
                }
                foreach ($institutions as $institution) {
                    if ($showDebug) {
                        Application::log("In getAllOtherInstitutions, looking at $institution");
                    }
                    if (!in_array(strtolower($institution), $seenInstitutionsInLowerCase) && !in_array(strtolower($institution), $currentProjectInstitutions)) {
                        $seenInstitutions[] = $institution;
                        $seenInstitutionsInLowerCase[] = strtolower($institution);
                    }
                }
            }
        }
        if ($showDebug) {
            Application::log("In getAllOtherInstitutions, returning ".json_encode($seenInstitutions));
        }
        return $seenInstitutions;
	}

	private static function getPriorAppointmentInstitutionFields() {
        return [
            "check_prev1_institution",
            "check_prev2_institution",
            "check_prev3_institution",
            "check_prev4_institution",
            "check_prev5_institution",
            "followup_prev1_institution",
            "followup_prev2_institution",
            "followup_prev3_institution",
            "followup_prev4_institution",
            "followup_prev5_institution",
            "init_import_prev1_institution",
            "init_import_prev2_institution",
            "init_import_prev3_institution",
            "init_import_prev4_institution",
            "init_import_prev5_institution",
        ];
    }

	private static function getTrainingInstitutionFields() {
	    return [
	        "imported_degree",
            "check_degree0_institution",
            "check_degree1_institution",
            "check_degree2_institution",
            "check_degree3_institution",
            "check_degree4_institution",
            "check_degree5_institution",
            "check_residency1_institution",
            "check_residency2_institution",
            "check_residency3_institution",
            "check_residency4_institution",
            "check_residency5_institution",
            "check_fellow1_institution",
            "check_fellow2_institution",
            "check_fellow3_institution",
            "check_fellow4_institution",
            "check_fellow5_institution",
            "followup_degree0_institution",
            "followup_degree1_institution",
            "init_import_degree0_institution",
            "init_import_degree1_institution",
            "init_import_degree2_institution",
            "init_import_degree3_institution",
            "init_import_degree4_institution",
            "init_import_degree5_institution",
            "init_import_residency1_institution",
            "init_import_residency2_institution",
            "init_import_residency3_institution",
            "init_import_residency4_institution",
            "init_import_residency5_institution",
            "init_import_fellow1_institution",
            "init_import_fellow2_institution",
            "init_import_fellow3_institution",
            "init_import_fellow4_institution",
            "init_import_fellow5_institution",
        ];
    }

	# returns a Result for when they left VUMC
	# used for the Scholars' survey and Follow-Up surveys
	private function getWhenLeftInstitution($rows) {
		$followupInstitutionField = "followup_institution";
        $checkInstitutionField = "check_institution";
        $importInstitutionField = "init_import_institution";
		$institutionCurrent = '1';
		$suffix = "_academic_rank_enddt";
	
		$followupRows = self::selectFollowupRows($rows);
		foreach ($followupRows as $instance => $row) {
			$prefices = array(
						"followup_prev1" => "followup",
						"followup_prev2" => "followup",
						"followup_prev3" => "followup",
						"followup_prev4" => "followup",
						"followup_prev5" => "followup",
					);
			if ($row[$followupInstitutionField] != $institutionCurrent) {
				$res = self::getResultForPrefices($prefices, $row, $suffix, $this->pid);
				if ($res->getValue()) {
					return $res;
				}
			}
		}

		$normativeRow = self::getNormativeRow($rows);
        if ($normativeRow[$checkInstitutionField] != $institutionCurrent) {
            $prefices = array(
                "check_prev1" => "scholars",
                "check_prev2" => "scholars",
                "check_prev3" => "scholars",
                "check_prev4" => "scholars",
                "check_prev5" => "scholars",
            );
            $res = self::getResultForPrefices($prefices, $normativeRow, $suffix, $this->pid);
            if ($res->getValue()) {
                return $res;
            }
        } else if ($normativeRow[$importInstitutionField] != $institutionCurrent) {
            $prefices = array(
                "init_import_prev1" => "manual",
                "init_import_prev2" => "manual",
                "init_import_prev3" => "manual",
                "init_import_prev4" => "manual",
                "init_import_prev5" => "manual",
            );
            $res = self::getResultForPrefices($prefices, $normativeRow, $suffix, $this->pid);
            if ($res->getValue()) {
                return $res;
            }
        }

		return new Result($normativeRow['identifier_left_date'], $normativeRow['identifier_left_date_source'], $normativeRow['identifier_left_date_sourcetype'], "", $this->pid);
	}

	# key = instance; value = REDCap data row
	private static function selectFollowupRows($rows) {
	    $followupRows = [];
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "followup") {
				$followupRows[$row['redcap_repeat_instance']] = $row;
			}
		}
		krsort($followupRows);	  // start with most recent survey
		return $followupRows;
	}

	# translates from innate ordering into new categories in summary_degrees
	private static function translateFirstDegree($num) {
		$translate = array(
				"" => "",
				1 => 1,
				2 => 4,
				6 => 6,
				7 => 3,
				8 => 3,
				9 => 3,
				10 => 2,
				11 => 2,
				12 => 3,
				13 => 3,
				14 => 6,
				15 => 3,
				16 => 6,
				17 => 6,
				18 => 6,
				3 => 6,
				4 => 6,
				5 => 6,
				);
		return $translate[$num];
	}



	# transforms a degree select box to something usable by other variables
	private static function transformSelectDegree($num, $methodology) {
		if (!$num) {
			return "";
		}
		if ($methodology == "Old") {
            $transform = [
                1 => 5,   # MS
                2 => 4,   # MSCI
                3 => 3,   # MPH
                4 => 6,   # other
            ];
        } else if ($methodology == "New") {
            $transform = [
                1 => "ms",   # MS
                2 => "msci",   # MSCI
                3 => "mph",   # MPH
                4 => 99,   # other
            ];
        } else {
		    throw new \Exception("Incorrect methodology $methodology");
        }
		return $transform[$num];
	}

	public static function getSourceChoices($metadata = array()) {
		$choices = self::getChoices($metadata);
		$exampleField = self::getExampleField();
		if (isset($choices[$exampleField])) {
			return $choices[$exampleField];
		}
		return array();
	}

	public function getOrder($defaultOrder, $fieldForOrder) {
		$sourceChoices = self::getSourceChoices($this->metadata);
		foreach ($this->metadata as $row) {
			if (($row['field_name'] == $fieldForOrder) && ($row['field_annotation'] != "")) {
				$newOrder = json_decode($row['field_annotation'], TRUE); 
				if ($newOrder) {
					$newVars = array();
					switch($fieldForOrder) {
						case "summary_degrees":
							foreach ($newOrder as $newField => $newSource) {
								if ($newField != $newSource) {
									# newly input
									$newVars[$newField] = $newSource;
								} else {
									# original
									foreach ($defaultOrder as $ary) {
										$newAry = array();
										foreach ($ary as $field => $source) {
											if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
												$newAry[$field] = $newSource;
											}
										}
										if (!empty($newAry)) {
											array_push($newVars, $newAry);
										}
									}
								}
							}
							break;
						case "summary_race_ethnicity":
							# $type is in (race, ethnicity)
							$possibleTypes = array("race", "ethnicity");
							foreach ($newOrder as $type => $ary) {
								if (in_array($type, $possibleTypes)) {
									$newVars[$type] = array();
									foreach ($ary as $newField => $newSource) {
										if ($newField != $newSource) {
											$newVars[$type][$newField] = $newSource;
										} else {
											if ($defaultOrder[$type]) {
												foreach ($defaultOrder[$type] as $field => $source) {
													if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
														$newVars[$type][$field] = $newSource;
													}
												}
											}
										}
									}
								} else {
									throw new \Exception("Encountered type '$type', which is not allowed in order");
								}
							}
							break;
						default:
							foreach ($newOrder as $newField => $newSource) {
								if ($newField != $newSource) {
									# newly input
									$newVars[$newField] = $newSource;
								} else {
									# original
									foreach ($defaultOrder as $field => $source) {
										if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
											$newVars[$field] = $newSource;
										}
									}
								}
							}
							break;
					}
					return $newVars;
				}
			}
		}
		return $defaultOrder;
	}

	public static function getNumStudySections() {
	    return 4;
    }

    public static function explodeInstitutions($institutionString) {
	    $institutions = preg_split("/\s*[,\/]\s*/", strtolower($institutionString));
        $newInstitutions = [];
        $replacementRegexes = [
            "/^the university of /i" => "",
            "/^university of /i" => "",
            "/university/i" => "univ",
            ];
        foreach ($institutions as $inst) {
            if ($inst) {
                $newInstitutions[] = $inst;
                foreach ($replacementRegexes as $regex => $replacement) {
                    if (preg_match($regex, $inst)) {
                        $inst2 = preg_replace($regex, $replacement, $inst);
                        if ($inst2) {
                            $newInstitutions[] = $inst2;
                        }
                    }
                }
            }
        }
        return $newInstitutions;
    }

    # to get all, make $field == "all"
	# add new fields here and getCalculatedFields
	public static function getDefaultOrder($field) {
		$orders = array();
        $orders["summary_degrees"] = array(
            array("override_degrees" => "manual"),
            array("followup_degree" => "followup"),
            array("imported_degree" => "manual"),
            array("check_degree1" => "scholars", "check_degree2" => "scholars", "check_degree3" => "scholars", "check_degree4" => "scholars", "check_degree5" => "scholars"),
            array("init_import_degree1" => "manual", "init_import_degree2" => "manual", "init_import_degree3" => "manual", "init_import_degree4" => "manual", "init_import_degree5" => "manual"),
            array("vfrs_graduate_degree" => "vfrs", "vfrs_degree2" => "vfrs", "vfrs_degree3" => "vfrs", "vfrs_degree4" => "vfrs", "vfrs_degree5" => "vfrs"),
            array("newman_new_degree1" => "new2017", "newman_new_degree2" => "new2017", "newman_new_degree3" => "new2017"),
            array("newman_data_degree1" => "data", "newman_data_degree2" => "data", "newman_data_degree3" => "data"),
            array("newman_demographics_degrees" => "demographics"),
            array("newman_sheet2_degree1" => "sheet2", "newman_sheet2_degree2" => "sheet2", "newman_sheet2_degree3" => "sheet2"),
        );
        $orders["identifier_orcid"] = array(
            "check_orcid_id" => "scholars",
            "followup_orcid_id" => "followup",
            "init_import_orcid_id" => "manual",
        );
        $orders["identifier_email"] = array(
            "check_email" => "scholars",
            "followup_email" => "followup",
            "init_import_email" => "manual",
            "ldap_mail" => "ldap",
        );
        $orders["identifier_userid"] = array(
            "ldap_uid" => "ldap",
        );
        $orders["identifier_vunet"] = array(
            "ldap_uid" => "ldap",
        );
        $orders["summary_primary_dept"] = array(
            "override_department1" => "manual",
            "promotion_department" => "manual",
            "check_primary_dept" => "scholars",
            "vfrs_department" => "vfrs",
            "init_import_primary_dept" => "manual",
            "ldap_vanderbiltpersonhrdeptnumber" => "ldap",
            "newman_new_department" => "new2017",
            "newman_demographics_department1" => "demographics",
            "newman_data_department1" => "data",
            "newman_sheet2_department1" => "sheet2",
        );
        $orders["summary_gender"] = [
            "override_gender" => "manual",
            "check_gender" => "scholars",
            "followup_gender" => "followup",
            "vfrs_gender" => "vfrs",
            "imported_gender" => "manual",
            "init_import_gender" => "manual",
            "newman_new_gender" => "new2017",
            "newman_demographics_gender" => "demographics",
            "newman_data_gender" => "data",
            "newman_nonrespondents_gender" => "nonrespondents",
        ];
        $orders["summary_transgender"] = [
            "check_transgender" => "scholars",
            "followup_transgender" => "followup",
            "init_import_transgender" => "manual",
        ];
        $orders["summary_sexual_orientation"] = [
            "check_sexual_orientation" => "scholars",
            "followup_sexual_orientation" => "followup",
            "init_import_sexual_orientation" => "manual",
        ];
        $orders["summary_race_ethnicity"] = [];
        $orders["summary_race_ethnicity"]["race"] = [
            "override_race" => "manual",
            "check_race" => "scholars",
            "vfrs_race" => "vfrs",
            "imported_race" => "manual",
            "init_import_race" => "manual",
            "newman_new_race" => "new2017",
            "newman_demographics_race" => "demographics",
            "newman_data_race" => "data",
            "newman_nonrespondents_race" => "nonrespondents",
        ];
        $orders["summary_race_ethnicity"]["ethnicity"] = [
            "override_ethnicity" => "manual",
            "check_ethnicity" => "scholars",
            "vfrs_ethnicity" => "vfrs",
            "imported_ethnicity" => "manual",
            "init_import_ethnicity" => "manual",
            "newman_new_ethnicity" => "new2017",
            "newman_demographics_ethnicity" => "demographics",
            "newman_data_ethnicity" => "data",
            "newman_nonrespondents_ethnicity" => "nonrespondents",
        ];
        $orders["summary_dob"] = [
            "check_date_of_birth" => "scholars",
            "vfrs_date_of_birth" => "vfrs",
            "override_dob" => "manual",
            "imported_dob" => "manual",
            "init_import_date_of_birth" => "manual",
            "newman_new_date_of_birth" => "new2017",
            "newman_demographics_date_of_birth" => "demographics",
            "newman_data_date_of_birth" => "data",
            "newman_nonrespondents_date_of_birth" => "nonrespondents",
        ];
        $orders["summary_citizenship"] = [
            "followup_citizenship" => "followup",
            "check_citizenship" => "scholars",
            "override_citizenship" => "manual",
            "imported_citizenship" => "manual",
            "init_import_citizenship" => "manual",
        ];
        $orders["identifier_institution"] = [
            "identifier_institution" => "manual",
            "promotion_institution" => "manual",
            "imported_institution" => "manual",
            "followup_institution" => "scholars",
            "check_institution" => "scholars",
            "check_undergrad_institution" => "scholars",
            "vfrs_current_degree_institution" => "vfrs",
            "init_import_institution" => "manual",
            "init_import_undergrad_institution" => "manual",
        ];
        $orders["identifier_left_job_title"] = array(
            "promotion_job_title" => "manual",
            "followup_job_title" => "scholars",
            "check_job_title" => "scholars",
            "init_import_job_title" => "manual",
        );
        $orders["identifier_left_job_category"] = array(
            "promotion_job_category" => "manual",
            "followup_job_category" => "scholars",
            "check_job_category" => "scholars",
            "init_import_job_category" => "manual",
        );
        $orders["identifier_left_department"] = array(
            "promotion_department" => "manual",
        );
        $orders["summary_current_division"] = array(
            "promotion_division" => "manual",
            "identifier_left_division" => "manual",
            "followup_division" => "followup",
            "check_division" => "scholars",
            "override_division" => "manual",
            "imported_division" => "manual",
            "init_import_division" => "manual",
            "identifier_starting_division" => "manual",
            "vfrs_division" => "vfrs",
        );
        $orders["summary_current_rank"] = array(
            "promotion_rank" => "manual",
            "override_rank" => "manual",
            "imported_rank" => "manual",
            "followup_academic_rank" => "followup",
            "check_academic_rank" => "scholars",
            "init_import_academic_rank" => "manual",
            "ldap_vanderbiltpersonjobname" => "ldap",
            "newman_new_rank" => "new2017",
            "newman_demographics_academic_rank" => "demographics",
        );
        $orders["summary_current_start"] = array(
            "promotion_in_effect" => "manual",
            "followup_academic_rank_dt" => "followup",
            "check_academic_rank_dt" => "scholars",
            "override_position_start" => "manual",
            "imported_position_start" => "manual",
            "init_import_academic_rank_dt" => "manual",
            "vfrs_when_did_this_appointment" => "vfrs",
        );
        $orders["summary_current_tenure"] = array(
            "followup_tenure_status" => "followup",
            "check_tenure_status" => "scholars",
            "override_tenure" => "manual",
            "imported_tenure" => "manual",
            "init_import_tenure_status" => "manual",
        );
        $orders["summary_mentor_userid"] = array(
            "override_mentor_userid" => "manual",
            "imported_mentor_userid" => "manual",
            "followup_primary_mentor_userid" => "followup",
            "check_primary_mentor_userid" => "scholars",
            "init_import_primary_mentor_userid" => "manual",
        );
        $orders["summary_mentor"] = array(
            "override_mentor" => "manual",
            "imported_mentor" => "manual",
            "followup_primary_mentor" => "followup",
            "check_primary_mentor" => "scholars",
            "init_import_primary_mentor" => "manual",
        );
        $orders["summary_disability"] = array(
            "check_disability" => "scholars",
            "vfrs_disability_those_with_phys" => "vfrs",
            "init_import_disability" => "manual",
        );
        $orders["summary_disadvantaged"] = array(
            "followup_disadvantaged" => "followup",
            "check_disadvantaged" => "scholars",
            "vfrs_disadvantaged_the_criteria" => "vfrs",
            "init_import_disadvantaged" => "manual",
        );
        $orders["summary_training_start"] = array(
            "identifier_start_of_training" => "manual",
            "check_degree0_start" => "scholars",
            "init_import_degree0_start" => "manual",
            "promotion_in_effect" => "manual",
        );
        $orders["summary_training_end"] = array(
            "identifier_end_of_training" => "manual",
            "check_degree0_month/check_degree0_year" => "scholars",
            "init_import_degree0_month/init_import_degree0_year" => "manual",
            "promotion_in_effect" => "manual",
        );
        $orders["identifier_ecommons_id"] = array(
            "check_ecommons_id" => "scholars",
            "followup_ecommons_id" => "followup",
            "init_import_ecommons_id" => "manual",
        );
        for ($i = 1; $i <= self::getNumStudySections(); $i++) {
            $orders["summary_study_section_name_".$i] = [
                "check_nih_standing_study_session_name_".$i => "scholars",
                "expertise_nih_standing_study_session_name_".$i => "expertise",
                "init_import_nih_standing_study_session_name_".$i => "manual",
            ];
            $orders["summary_other_standing_".$i] = [
                "check_other_standing_".$i => "scholars",
                "expertise_other_standing_".$i => "expertise",
                "init_import_other_standing_".$i => "manual",
            ];
        }

		if (isset($orders[$field])) {
			return $orders[$field];
		} else if ($field == "all") {
			return $orders;
		}
		return array();
	}

	# returns associative array with key institution-field => degree-field
    # bachelor's degree not included
	public function getAllInstitutionFields() {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
	    $fields = array();

	    for ($i = 0; $i <= 5; $i++) {
            $institutionFieldInit = "init_import_degree".$i."_institution";
            $degreeFieldInit = "init_import_degree".$i;
	        $institutionField = "check_degree".$i."_institution";
	        $degreeField = "check_degree".$i;
            if (in_array($institutionField, $metadataFields) && in_array($degreeField, $metadataFields)) {
                $fields[$institutionField] = $degreeField;
            }
            if (in_array($institutionFieldInit, $metadataFields) && in_array($degreeFieldInit, $metadataFields)) {
                $fields[$institutionFieldInit] = $degreeFieldInit;
            }
        }

        $institutionField = "imported_degree_institution";
        $degreeField = "imported_degree";
        if (in_array($institutionField, $metadataFields) && in_array($degreeField, $metadataFields)) {
            $fields[$institutionField] = $degreeField;
        }

		// array("followup_degree" => "followup"),
        $institutionField = "followup_degree_institution";
	    $degreeField = "followup_degree";
        if (in_array($institutionField, $metadataFields) && in_array($degreeField, $metadataFields)) {
            $fields[$institutionField] = $degreeField;
        }

		// array("vfrs_graduate_degree" => "vfrs", "vfrs_degree2" => "vfrs", "vfrs_degree3" => "vfrs", "vfrs_degree4" => "vfrs", "vfrs_degree5" => "vfrs", "vfrs_please_select_your_degree" => "vfrs"),
        $institutionField = "vfrs_degree1_institution";
        $degreeField = "vfrs_graduate_degree";
        if (in_array($institutionField, $metadataFields) && in_array($degreeField, $metadataFields)) {
            $fields[$institutionField] = $degreeField;
        }
        for ($i = 2; $i <= 5; $i++) {
            $institutionField = "vfrs_degree".$i."_institution";
            $degreeField = "vfrs_degree".$i;
            if (in_array($institutionField, $metadataFields) && in_array($degreeField, $metadataFields)) {
                $fields[$institutionField] = $degreeField;
            }
        }

	    return array_unique($fields);
    }

    private function checkAllDegrees($rows) {
	    $choices = REDCapManagement::getChoices($this->metadata);
        $field = "summary_all_degrees";
        if (isset($choices[$field])) {
            $degrees = $this->findAllDegrees($rows);
            $results = [];
            foreach ($choices[$field] as $key => $label) {
                if (in_array($key, $degrees)) {
                    $newValue = 1;
                } else {
                    $newValue = 0;
                }
                $results[$field."___".$key] = new Result($newValue, "", "", "", $this->pid);
            }
            return $results;
        }
        return [];
    }

    public static function getDoctoralRegexes() {
        return ["/MD/", "/PhD/i", "/DPhil/i", "/PharmD/i", "/PsyD/i", "/DO/", "/AuD/i", "/DMP/", "/DNP/", "/DrPH/", "/DSW/", "/EdD/", "/SciD/"];
    }

    public function findAllDegrees($rows) {
        $choices = REDCapManagement::getChoices($this->metadata);
        $exampleFields = ["check_degree0", "check_degree1"];
        $methodology = FALSE;
        foreach ($exampleFields as $exampleField) {
            if (($choices[$exampleField][18] == "MD/PhD") || ($choices[$exampleField][11] == "MHS")) {
                $methodology = "Old";
                break;
            } else if (isset($choices[$exampleField]["md"])) {
                $methodology = "New";
                break;
            }
        }
        if (!$methodology) {
            throw new \Exception("Could not match field for ".json_encode($exampleFields)." in metadata!");
        }
        return $this->findAllDegreesHelper($rows, $methodology);
    }

    private function findAllDegreesHelper($rows, $methodology) {
        # move over and then down
        $order = self::getDefaultOrder("summary_degrees");
        $order = $this->getOrder($order, "summary_degrees");

        $choices = self::getChoices($this->metadata);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);

        $normativeRow = self::getNormativeRow($rows);
        $followupRows = self::selectFollowupRows($rows);

        # combines degrees
        $degrees = array();
        foreach ($order as $variables) {
            foreach ($variables as $variable => $source) {
                if ($variable == "vfrs_please_select_your_degree") {
                    $normativeRow[$variable] = self::transformSelectDegree($normativeRow[$variable], $methodology);
                }
                if ($source == "followup") {
                    foreach ($followupRows as $row) {
                        if ($row[$variable] && !in_array($row[$variable], $degrees)) {
                            $degrees[] = $row[$variable];
                        }
                    }
                }
                if ($normativeRow[$variable] && !in_array($normativeRow[$variable], $degrees)) {
                    if (in_array("summary_all_degrees", $metadataFields) && isset($choices[$variable])) {
                        $foundIdx = FALSE;
                        foreach ($choices["summary_all_degrees"] as $idx => $label) {
                            if ($label == $choices[$variable][$normativeRow[$variable]]) {
                                $foundIdx = $idx;
                                break;
                            }
                        }
                        if ($foundIdx === FALSE) {
                            if (!in_array($normativeRow[$variable], $degrees)) {
                                $degrees[] = $normativeRow[$variable];
                            }
                        } else {
                            if (!in_array($foundIdx, $degrees)) {
                                $degrees[] = $foundIdx;
                            }
                        }
                    } else {
                        if (!in_array($normativeRow[$variable], $degrees)) {
                            $degrees[] = $normativeRow[$variable];
                        }
                    }
                }
            }
        }
        return $degrees;
    }

    private static function translateDegreesFromList($degrees) {
	    $value = "";
        if (empty($degrees)) {
            return new Result("", "");
        } else if (in_array("mdphd", $degrees)) {
            $value = 10;  # MD/PhD
        } else if (in_array("md", $degrees) || in_array(1, $degrees) || in_array(9, $degrees) || in_array(10, $degrees) || in_array(7, $degrees) || in_array(8, $degrees) || in_array(14, $degrees) || in_array(12, $degrees)) { # MD
            if (in_array("phd", $degrees) || in_array(2, $degrees) || in_array(9, $degrees) || in_array(10, $degrees)) {
                $value = 10;  # MD/PhD
            } else if (in_array("mph", $degrees) || in_array(3, $degrees) || in_array(16, $degrees) || in_array(18, $degrees)) { # MPH
                $value = 7;
            } else if (in_array("msci", $degrees) || in_array(4, $degrees) || in_array(7, $degrees)) { # MSCI
                $value = 8;
            } else if (in_array("ms", $degrees) || in_array(5, $degrees) || in_array(8, $degrees)) { # MS
                $value = 9;
            } else if (in_array(99, $degrees) || in_array(6, $degrees) || in_array(13, $degrees) || in_array(14, $degrees)) { # Other
                $value = 7;     # MD + other
            } else if (in_array("mhs", $degrees) || in_array(11, $degrees) || in_array(12, $degrees)) { # MHS
                $value = 12;
            } else {
                $value = 1;   # MD only
            }
        } else if (in_array("phd", $degrees) || in_array(2, $degrees)) { # PhD
            if (in_array(11, $degrees)) {
                $value = 10;  # MD/PhD
            } else if (in_array("mph", $degrees) || in_array(3, $degrees)) { # MPH
                $value = 2;
            } else if (in_array("msci", $degrees) || in_array(4, $degrees)) { # MSCI
                $value = 2;
            } else if (in_array("ms", $degrees) || in_array(5, $degrees)) { # MS
                $value = 2;
            } else if (in_array(99, $degrees) || in_array(6, $degrees)) { # Other
                $value = 2;
            } else {
                $value = 2;     # PhD only
            }
        } else if (in_array(99, $degrees) || in_array(6, $degrees)) {  # Other
            if (in_array("md", $degrees) || in_array(1, $degrees)) {   # MD
                $value = 7;  # MD + other
            } else if (in_array("phd", $degrees) || in_array(2, $degrees)) {  # PhD
                $value = 2;
            } else {
                $value = 6;
            }
        } else if (in_array("mph", $degrees) || in_array(3, $degrees)) {  # MPH
            $value = 6;
        } else if (in_array("msci", $degrees) || in_array(4, $degrees)) {  # MSCI
            $value = 6;
        } else if (in_array("ms", $degrees) || in_array(5, $degrees)) {  # MS
            $value = 6;
        } else if (in_array("psyd", $degrees) || in_array(15, $degrees)) {  # PsyD
            $value = 6;
        } else {
            Application::log("Unidentified degrees ".REDCapManagement::json_encode_with_spaces($degrees).". Assigning other.");
            $value = 6;
        }
	    return $value;
    }

	private function getDegrees($rows) {
	    $degrees = $this->findAllDegrees($rows);
        $value = self::translateDegreesFromList($degrees);

        $newValue = self::translateFirstDegree($value);
        return new Result($newValue, "", "", "", $this->pid);
	}

	private function getPrimaryDepartment($rows) {
		$field = "summary_primary_dept";
        $vars = self::getDefaultOrder($field);
        $vars = $this->getOrder($vars, $field);
        $previousField = "";

        # avoid conflict between a department and the choices
        do {
            $proceed = FALSE;
            $filteredVars = [];
            foreach ($vars as $varField => $source) {
                if ($previousField === "") {
                    $filteredVars[$varField] = $source;
                } else if ($previousField == $varField) {
                    $previousField = "";
                }
            }

            $result = self::searchRowsForVars($rows, $filteredVars, FALSE, $this->pid, isset($_GET['test']));
            if (isset($_GET['test'])) {
                echo "A Got ".$result->getValue()." from ".$result->getSource()."<br>";
            }
            $value = $result->getValue();
            if ($result->getSource() == "vfrs") {
                $value = self::transferVFRSDepartment($value);
            }
            if ($value == "") {
                return new Result("", "", "", "", $this->pid);
            }

            $choices = self::getChoices($this->metadata);
            if (isset($choices[$field]) && !isset($choices[$field][$value])) {
                foreach ($choices[$field] as $idx => $label) {
                    if ($label == $value) {
                        if (isset($_GET['test'])) {
                            echo "Matched label, setting to $idx<br>";
                        }
                        $value = $idx;
                        break;
                    }
                }
            }
            if (!isset($choices[$field][$value])) {
                # from text entry and no match with our databank
                if (isset($_GET['test'])) {
                    echo "Moving to next because value of $value<br>";
                }
                $value = "";
                $proceed = TRUE;
                $previousField = $result->getField();
            }
        } while ($proceed);
		return new Result($value, $result->getSource(), "", "", $this->pid);
	}

	# VFRS did not use the 6-digit classification, so we must translate
	private static function transferVFRSDepartment($dept) {
		$exchange = array(
					1       => 104300,
					2       => 104250,
					3       => 104785,
					4       => 104268,
					5       => 104286,
					6       => 104705,
					7       => 104280,
					8       => 104791,
					9       => 999999,
					10      => 104782,
					11      => 104368,
					12      => 104270,
					13      => 104400,
					14      => 104705,
					15      => 104425,
					16      => 104450,
					17      => 104366,
					18      => 104475,
					19      => 104781,
					20      => 104500,
					21      => 104709,
					22      => 104595,
					23      => 104290,
					24      => 104705,
					25      => 104625,
					26      => 104529,
					27      => 104675,
					28      => 104650,
					29      => 104705,
					30      => 104726,
					31      => 104775,
					32      => 999999,
					33      => 106052,
					34      => 104400,
					35      => 104353,
					36      => 120430,
					37      => 122450,
					38      => 120660,
					39      => 999999,
					40      => 104705,
					41      => 104366,
					42      => 104625,
					43      => 999999,
				);
		if (isset($exchange[$dept])) {
			return $exchange[$dept];
		}
		return "";
	}

    public function getSexualOrientation($rows) {
        $result = $this->getGenericValueForField($rows, "summary_sexual_orientation");
        return $result;
    }

    public function getTransgenderStatus($rows) {
        $result = $this->getGenericValueForField($rows, "summary_transgender");
        return $result;
    }

    public function getGender($rows) {
	    $summaryField = "summary_gender";
        $result = $this->getGenericValueForField($rows, $summaryField);
        $choices = REDCapManagement::getChoices($this->metadata);

		# must reverse for certain sources
		$tradOrder = array("manual", "scholars", "followup");
		if ($result->getValue()) {
			if (in_array($result->getSource(), $tradOrder)) {
				return $result;
			}
			$source = $result->getSource();
			$value = $result->getValue();
			$field = $result->getField();
			if ($value == 1) {  # Male
				return new Result(2, $source, "", "", $this->pid);
			} else if ($value == 2) {   # Female
				return new Result(1, $source, "", "", $this->pid);
			} else if ($choices[$field] && $choices[$field][$value]) {
			    $label = $choices[$field][$value];
			    $newValue = FALSE;
                if (preg_match("/nonbinary/i", $label) || preg_match("/non-binary/i", $label)) {
                    $newValue = 3;
                } else if (preg_match("/other/i", $label)) {
                    $newValue = 99;
                } else if (preg_match("/prefer/i", $label) && preg_match("/not/i", $label) && preg_match("/answer/i", $label)) {
                    $newValue = 98;
                }
                if ($newValue && $choices[$summaryField][$newValue]) {
                    return new Result($newValue, $source, "", "", $this->pid);
                }
            }
			# forget others
		}
		return new Result("", "");
	}

	# returns array of 3 (overall classification, race source, ethnicity source)
	public function getRaceEthnicity($rows) {
		$field = "summary_race_ethnicity";
		$order = self::getDefaultOrder($field);
		$order = $this->getOrder($order, $field);
		$normativeRow = self::getNormativeRow($rows);
		$choices = self::getChoices($this->metadata);

		$race = "";
		$raceSource = "";
		foreach ($order["race"] as $variable => $source) {
			if (isset($normativeRow[$variable]) && ($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 8)) {
				$race = $normativeRow[$variable];
				$raceSource = $source;
				break;
			}
		}
		if ($race === "") {
			return new RaceEthnicityResult("", "", "");
		}
		$eth = "";
		$ethSource = "";
		foreach ($order["ethnicity"] as $variable => $source) {
			if (isset($normativeRow[$variable]) && ($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 4)) {
				$eth = $normativeRow[$variable];
				$ethSource = $source;
				break;
			}
		}
		$val = "";
		if (($race == 98) || ($eth == 98) || ($race == 99) || ($eth == 99)) {
		    # 98, Unknown | 99, Prefer not to Answer
		    if ($race == 99 || $eth == 99) {
		        $val = 99;
            } else {
                $val = 98;
            }
            if ($race != $val) {
                $raceSource = "";
            }
            if ($eth != $val) {
                $ethSource = "";
            }
            return new RaceEthnicityResult($val, $raceSource, $ethSource, $this->pid);
        } else if ($race == 2) {   # Asian
			$val = 5;
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		} else if ($race == 1) {    # American Indian or Native Alaskan
			$val = 9;
			if (isset($choices[$field]) && !isset($choices[$field][$val])) {
				$val = 6;
			}
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		} else if ($race == 3) {    # Hawaiian or Other Pacific Islander
			$val = 10;
			if (isset($choices[$field]) && !isset($choices[$field][$val])) {
				$val = 6;
			}
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		} else if ($race == 6) {    # More Than One Race
            $val = 11;
            if (isset($choices[$field]) && !isset($choices[$field][$val])) {
                $val = 6;
            }
            return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
        }
		if ($eth == "") {
			if ($race == 5) { # White
				$val = 7;
			} else if ($race == 4) { # Black
				$val = 8;
			}
			if ($val) {
				if (!isset($choices[$field][$val])) {
					if ($val == 7) {
						$val = 1;   // white, non-Hisp
					} else if ($val == 8) {
						$val = 2;   // black, non-Hisp
					}
				}
				return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
			}
		}
		if ($eth == 1) { # Hispanic
			if ($race == 5) { # White
				$val = 3;
			} else if ($race == 4) { # Black
				$val = 4;
			}
		} else if ($eth == 2) {  # non-Hisp
			if ($race == 5) { # White
				$val = 1;
			} else if ($race == 4) { # Black
				$val = 2;
			}
		}
		if ($val === "") {
			$val = 6;  # other
		}
		return new RaceEthnicityResult($val, $raceSource, $ethSource, $this->pid);
	}

	# convert date
	private static function convertToYYYYMMDD($date) {
		$nodes = preg_split("/[\-\/]/", $date);
		if (($nodes[0] == 0) || ($nodes[1] == 0)) {
			return "";
		}
		if ($nodes[0] > 1900) {
			return $nodes[0]."-".$nodes[1]."-".$nodes[2];
		}
		if ($nodes[2] < 1900) {
			if ($nodes[2] < 20) {
				$nodes[2] = 2000 + $nodes[2];
			} else {
				$nodes[2] = 1900 + $nodes[2];
			}
		}
		// from MDY
		return $nodes[2]."-".$nodes[0]."-".$nodes[1];
	}

	# finds date-of-birth
	public function getDOB($rows) {
        $result = $this->getGenericValueForField($rows, "summary_dob");
		$date = $result->getValue();
		if ($date) {
			$date = self::convertToYYYYMMDD($date);
		}

		return new Result($date, $result->getSource(), "", "", $this->pid);
	}

	public function getCitizenship($rows) {
        $result = $this->getGenericValueForField($rows, "summary_citizenship");
		if ($result->getValue() == "") {
			$selectChoices = array(
						"vfrs_citizenship" => "vfrs",
						);
			foreach ($selectChoices as $field => $fieldSource) {
				if ($fieldSource == "vfrs") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field])) {
							$fieldValue = trim(strtolower($row[$field]));
							if ($fieldValue == "1") {
								# U.S. citizen, source unknown
								return new Result('5', $fieldSource, "", "", $this->pid);
							} else if ($fieldValue) {
								# Non U.S. citizen, status unknown
								return new Result('6', $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}

			$usValues = array("us", "u.s.", "united states", "usa", "u.s.a.");    // lower case
			$textSources = array(
						"newman_demographics_citizenship" => "demographics",
						);
			foreach ($textSources as $field => $fieldSource) {
				if ($fieldSource == "demographics") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field])) {
							$fieldValue = trim(strtolower($row[$field]));
							if (in_array($fieldValue, $usValues)) {
								# U.S. citizen, source unknown
								return new Result('5', $fieldSource, "", "", $this->pid);
							} else if ($fieldValue) {
								# Non U.S. citizen, status unknown
								return new Result('6', $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private static function getDateFieldForSource($source, $field) {
		switch($source) {
			case "followup":
				return "followup_date";
			case "manual":
                if (preg_match("/^promotion_/", $field)) {
                    return "promotion_in_effect";
                } else if (preg_match("/^init_import_/", $field)) {
                    return "init_import_date";
				} else if (preg_match("/^override_/", $field)) {
					return $field."_time";
				}
				return "";
			case "new_2017":
				return "2017-10-01";
            case "scholars":
                return "check_date";
		}
		return "";
	}

	# $vars is listed in order of priority; key = variable, value = data source
	private static function searchRowsForVars($rows, $vars, $byLatest = FALSE, $pid = "", $showDebug = FALSE) {
		$result = new Result("", "");
        $aryInstance = "";
        $latestTs = 0;
		foreach ($vars as $var => $source) {
			$splitVar = explode("/", $var);
			foreach ($rows as $row) {
				if ($row[$var] || ((count($splitVar) > 1) && $row[$splitVar[0]] && $row[$splitVar[1]])) {
				    if ($showDebug) {
				        if ($row[$var]) {
				            Application::log("Found at $var: ".$row[$var]);
                        } else if (count($splitVar) > 1) {
                            Application::log("Found at {$splitVar[0]}: ".$row[$splitVar[0]]." and {$splitVar[1]}: ".$row[$splitVar[1]]);
                        }
                    }
					$date = "";
					if (count($splitVar) > 1) {
						# YYYY-mm-dd
						$varValues = array();
						foreach ($splitVar as $v) {
							array_push($varValues, $row[$v]);
						}
						if (count($varValues) == 3) {
							$date = implode("-", $varValues);
						} else if (count($varValues) == 2) {
							# YYYY-mm
							$startDay = "01";
							$date = implode("-", $varValues)."-".$startDay;
						} else {
							throw new \Exception("Cannot interpret split variables: ".json_encode($varValues));
						}
					} else {
						$dateField = self::getDateFieldForSource($source, $var);
						if ($dateField && $row[$dateField]) {
							$date = $row[$dateField];
						} else if ($dateField && !in_array($dateField, ["check_date", "init_import_date"])) {
							$date = $dateField;
						}
					}
					if ($byLatest) {
						# order by date
						if ($date) {
                            if ($showDebug) {
                                Application::log("$var: Date: ".$date);
                            }
							$currTs = strtotime($date);
							if ($currTs > $latestTs) {
							    if ($showDebug) {
                                    Application::log("$var: Setting date: ".$date." and value: ".$row[$var]);
                                }
								$latestTs = $currTs;
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
								$result->setField($var);
								$result->setInstance($row['redcap_repeat_instance']);
							}
						} else if (!$latestTs) {
                            if ($showDebug) {
                                Application::log("$var: Transformed Date: ".self::transformIfDate($row[$var]));
                            }
							$result = new Result(self::transformIfDate($row[$var]), $source, "", "", $pid);
							$result->setField($var);
							$result->setInstance($row['redcap_repeat_instance']);
							$latestTs = 1; // nominally low value
                        }
					} else {
						if ($row['redcap_repeat_instrument'] == $source) {
							# get earliest instance - i.e., lowest repeat_instance
							if (!$aryInstance
								|| ($aryInstance > $row['redcap_repeat_instance'])) {
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
								$result->setField($var);
								$result->setInstance($row['redcap_repeat_instance']);
								$aryInstance = $row['redcap_repeat_instance'];
							}
						} else {
							$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
							$result->setField($var);
							return $result;
						}
					}
				}
			}
			if ($aryInstance) {
				return $result;
			}
		}
		if ($byLatest) {
            if ($showDebug) {
                Application::log("Returning '".$result->getValue()."'");
            }
			return $result;
		}
        if ($showDebug) {
            Application::log("Returning blank");
        }
		return new Result("", "");
	}

	private static function transformIfDate($value) {
		if (preg_match("/^(\d+)[\/\-](\d\d\d\d)$/", $value, $matches)) {
			# MM/YYYY
			$month = $matches[1];
			$year = $matches[2];
			$day = "01";
			return $year."-".$month."-".$day;
			
		} else if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d\d\d$/", $value)) {
			# MM/DD/YYYY
			return self::convertToYYYYMMDD($value);
		}
		return $value;
	}

	public function getInstitutionText() {
		$result = $this->getInstitution($this->rows);
		return $result->getValue();
	}

	private function getInstitution($rows) {
	    $showDebug = SHOW_DEBUG_FOR_INSTITUTIONS;
        $result = $this->getGenericValueForField($rows, "identifier_institution", TRUE, SHOW_DEBUG_FOR_INSTITUTIONS);
		$value = $result->getValue();

		if ($showDebug) {
		    Application::log("getInstitution has $value");
        }

		if (is_numeric($value)) {
			$choices = self::getChoices($this->metadata);
			$fieldName = $result->getField();
			if (isset($choices[$fieldName]) && isset($choices[$fieldName][$value])) {
				$newValue = $choices[$fieldName][$value];
				if ($newValue == "Other") {
					foreach ($rows as $row) {
						if ($row[$fieldName."_oth"]) {
							$newValue = $row[$fieldName."_oth"];
							break;
						} else if ($row[$fieldName."_other"]) {
							$newValue = $row[$fieldName."_other"];
							break;
						}
					}
				}
				$result->setValue($newValue);
			}
			return $result;
		} else if (($value == "") || ($value == Application::getUnknown())) {
            if ($showDebug) {
                Application::log("getInstitution returning blank");
            }
			return new Result("", "");
		} else {
            if ($showDebug) {
                Application::log("getInstitution returning ".$result->getValue());
            }
			# typical case
			return $result;
		}
	}

	public function getCurrentDivision($rows) {
        $result = $this->getGenericValueForField($rows, "summary_current_division");
		if ($result->getValue() == "N/A") {
			return new Result("", "");
		}
		if ($result->getValue() == "") {
			$deptName = $this->getPrimaryDepartmentText();
			$nodes = preg_split("/\//", $deptName);
			if (count($nodes) == 2) {
				$deptResult = $this->getPrimaryDepartment($rows);
				return new Result($nodes[1], $deptResult->getSource(), "", "", $this->pid);
			}
		}
		return $result;
	}

	private function getCurrentRank($rows) {
		$vars = self::getDefaultOrder("summary_current_rank");
		$vars = $this->getOrder($vars, "summary_current_rank");
		$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
        // Application::log($this->getName()." summary_current_rank: ".$result->displayInText());
		if (!$result->getValue()) {
			$otherFields = array(
						"vfrs_current_appointment" => "vfrs",
						);
			foreach ($otherFields as $field => $fieldSource) {
				if ($fieldSource == "vfrs") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field] != "")) {
							# VFRS: 1, Research Instructor|2, Research Assistant Professor|3, Instructor|4, Assistant Professor|5, Other
							# Summary: 1, Research Fellow | 2, Clinical Fellow | 3, Instructor | 4, Research Assistant Professor | 5, Assistant Professor | 6, Associate Professor | 7, Professor | 8, Other
							switch($row[$field]) {
								case 1:
									$val = 3;
									break;
								case 2:
									$val = 4;
									break;
								case 3:
									$val = 3;
									break;
								case 4:
									$val = 5;
									break;
								case 5:
									$val = 8;
									break;
								default:
									$val = "";
									break;
							}
							if ($val) {
								return new Result($val, $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private function getCurrentAppointmentStart($rows) {
        return $this->getGenericValueForField($rows, "summary_current_start");
	}

	public function getEndOfK($kTypes = [1, 2, 3, 4]) {
	    $lastEndDate = "";
	    foreach ($this->rows as $row) {
	        if (($row['redcap_repeat_instrument'] == "") && ($row['redcap_repeat_instance'] == "")) {
                $startOfR = $row['summary_first_r01_or_equiv'];
                if ($startOfR) {
                    $ts = strtotime($startOfR);
                    $ts -= 24 * 3600;
                    $oneDayBeforeStartOfR = date("Y-m-d", $ts);
                }
	            # get end of last K
	            for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
	                $type = $row['summary_award_type_'.$i];
	                if (in_array($type, $kTypes)) {
                        $startDate = $row['summary_award_date_'.$i];
                        $endDate = $row['summary_award_end_date_'.$i];
                        if (!$endDate) {
	                        $kLength = Scholar::getKLength($type);
	                        $endDate = REDCapManagement::addYears($startDate, $kLength);
                        }
                        if (!$endDate || ($oneDayBeforeStartOfR && REDCapManagement::dateCompare($endDate, ">", $oneDayBeforeStartOfR))) {
                            $endDate = $oneDayBeforeStartOfR;
                        }
                        if ($endDate && (!$lastEndDate || REDCapManagement::dateCompare($lastEndDate, "<", $endDate))) {
                            $lastEndDate = $endDate;
                        }
                    }
                }
            }
        }
	    return $lastEndDate;
    }

	private function getTenureStatus($rows) {
        $result = $this->getGenericValueForField($rows, "summary_current_tenure");
		if ($result->getValue() == "") {
			$otherFields = array(
						"vfrs_tenure" => "vfrs",
						);
			foreach ($otherFields as $field => $fieldSource) {
				foreach ($rows as $row) {
					if (isset($row[$field]) && ($row[$field] != "")) {
						return new Result($row[$field], $fieldSource, "", "", $this->pid);
					}
				}
			} 
		}

		$rankResult = $this->getCurrentRank($rows);
		if (in_array($rankResult->getValue(), [6, 7])) {
		    return new Result(3, $rankResult->getSource(), $rankResult->getSourceType(), "", $this->pid);;   // Tenured
        }
        if (in_array($rankResult->getValue(), [4])) {
            return new Result(1, $rankResult->getSource(), $rankResult->getSourceType(), "", $this->pid);   // Not Tenure track
        }

        $tenured = ["Professor", "Assoc Professor"];
        foreach ($rows as $row) {
            if ($row['redcap_repeat_instrument'] == "ldap") {
                if (in_array($row['ldap_vanderbiltpersonjobname'], $tenured)) {
                    return new Result(3, "ldap", "Computer-Generated", "", $this->pid);;
                } else if (preg_match("/Research/", $row['ldap_vanderbiltpersonjobname'])) {
                    return new Result(1, "ldap", "Computer-Generated", "", $this->pid);;
                }
            }
        }
		return $result;
	}

	private static function isNormativeRow($row) {
		return (($row['redcap_repeat_instrument'] == "") && ($row['redcap_repeat_instance'] == ""));
	}

	private function loadDemographics() {
		$this->demographics = array();
		$fields = self::getDemographicFields($this->metadata);
		$rows = $this->rows;

		foreach ($rows as $row) {
			if (self::isNormativeRow($row)) {
				foreach ($fields as $field => $func) {
					if (isset($row[$field])) {
						$this->demographics[$field] = $row[$field];
					} else {
						$this->demographics[$field] = "";
					}
				}
			}
		}
	}

	private function getJobCategory($rows) {
		return $this->searchForJobMove("identifier_left_job_category", $rows);
	}

	private function getNewDepartment($rows) {
		return $this->searchForJobMove("identifier_left_department", $rows);
	}

	private function getJobTitle($rows) {
		return $this->searchForJobMove("identifier_left_job_title", $rows);
	}

	private function searchForJobMove($field, $rows) {
		$institutionResult = $this->getInstitution($rows);
		$value = $institutionResult->getValue();
		$vars = self::getDefaultOrder($field);
        $vars = $this->getOrder($vars, $field);
		if ($value) {
			return $this->matchWithInstitutionResult($institutionResult, $rows, $vars);
		} else {
			# no institution information
            return self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		}
	}

	private function matchInstitutionInRow($value, $row) {
        $vars = self::getDefaultOrder("identifier_institution");
        $vars = $this->getOrder($vars, "identifier_institution");

        foreach ($vars as $field => $source) {
            if ($row[$field] == $value) {
                return $field;
            }
        }

        return FALSE;
    }

	private function matchWithInstitutionResult($institutionResult, $rows, $vars) {
		$fieldName = $institutionResult->getField();
		$instance = $institutionResult->getInstance();
        $source = $institutionResult->getSource();
        $value = $institutionResult->getValue();
		if (!$instance) {
			$instances = array("", "1");
		} else {
			$instances = array($instance);
		}
		foreach ($rows as $row) {
			$currInstance = ($row['redcap_repeat_instance'] ? $row['redcap_repeat_instance'] : "");
			if (in_array($currInstance, $instances) && $this->matchInstitutionInRow($value, $row)) {
				foreach ($vars as $origField => $origSource) {
					if (($source == $origSource) && $row[$origField]) {
					    $result = new Result($row[$origField], $source, "", "", $this->pid);
						$result->setField($origField);
						$result->setInstance($currInstance);
						return $result;
					}
				}
			}
		}
		return new Result("", "");
	}

	public function getDemographicsArray() {
		return $this->demographics;
	}

	private static function getDemographicFields($metadata) {
		return self::getCalculatedFields($metadata);
	}

	public function getEarliestDateInResearch($type) {
        return $this->getDateInResearch($type, "first");
    }

    public function getLatestDateInResearch($type) {
        return $this->getDateInResearch($type, "last");
    }

    private function getDateInResearch($type, $variableType) {
        if (in_array($type, ["Publications", "Publication", "Pub", "Pubs"])) {
            return REDCapManagement::findField($this->rows, $this->recordId, "summary_$variableType"."_pub_activity");
        } else if (in_array($type, ["Grants", "Grant"])) {
            return REDCapManagement::findField($this->rows, $this->recordId, "summary_$variableType"."_grant_activity");
        } else if ($type == "Both") {
            $dates = [
                REDCapManagement::findField($this->rows, $this->recordId, "summary_$variableType"."_grant_activity"),
                REDCapManagement::findField($this->rows, $this->recordId, "summary_$variableType"."_pub_activity"),
            ];
            $timestamps = [];
            foreach ($dates as $date) {
                if ($date && REDCapManagement::isDate($date)) {
                    $ts = strtotime($date);
                    if ($ts) {
                        $timestamps[] = $ts;
                    }
                }
            }
            if (count($timestamps) > 0) {
                if ($variableType == "last") {
                    rsort($timestamps);
                } else if ($variableType == "first") {
                    sort($timestamps);
                } else {
                    throw new \Exception("Could not locate variable type $variableType");
                }
                $date = date("Y-m-d", $timestamps[0]);
                if (isset($_GET['test'])) {
                    echo $this->recordId." $variableType: dates=".json_encode($dates)." with timestamps=".json_encode($timestamps)." returning $date<br>";
                }
                return $date;
            }
            return "";
        } else {
            return "";
        }
    }

    public function getInactiveTimeInResearch($type = "Both", $measurement = "days") {
        $latest = $this->getLatestDateInResearch($type);
        $now = date("Y-m-d");
        return self::getDateDiff($latest, $now, $measurement);
    }

    public function getTimeInResearch($type = "Both", $measurement = "days")
    {
        $earliest = $this->getEarliestDateInResearch($type);
        $latest = $this->getLatestDateInResearch($type);
        return self::getDateDiff($earliest, $latest, $measurement);
    }

    # returns date2 - date1
    public static function getDateDiff($date1, $date2, $measurement) {
        if (REDCapManagement::isDate($date1) && REDCapManagement::isDate($date2)) {
            if ($measurement == "days") {
                $unit = "d";
            } else if ($measurement == "months") {
                # convention: unit = m for minute; M for month
                $unit = "M";
            } else if ($measurement == "years") {
                $unit = "y";
            } else if ($measurement == "hours") {
                $unit = "h";
            } else if ($measurement == "minutes") {
                # convention: unit = m for minute; M for month
                $unit = "m";
            } else if ($measurement == "seconds") {
                $unit = "s";
            } else {
                throw new \Exception("Invalid measurement $measurement");
            }
            $diff = REDCapManagement::datediff($date1, $date2, $unit);
            if (REDCapManagement::dateCompare($date1, ">", $date2)) {
                return 0-$diff;
            } else {
                return $diff;
            }
        }
        return FALSE;
    }

	# add new fields here and getDefaultOrder
	private static function getCalculatedFields($metadata) {
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
		$ary = [
            "summary_first_grant_activity" => "calculateFirstGrantActivity",
            "summary_last_grant_activity" => "calculateLastGrantActivity",
            "summary_first_pub_activity" => "calculateFirstPubActivity",
            "summary_last_pub_activity" => "calculateLastPubActivity",
            "summary_coeus_name" => "calculateCOEUSName",
            "summary_survey" => "getSurvey",
            "identifier_left_date" => "getWhenLeftInstitution",
            "identifier_institution" => "getAllOtherInstitutionsAsList",
            "identifier_left_job_title" => "getJobTitle",
            "identifier_left_job_category" => "getJobCategory",
            "identifier_left_department" => "getNewDepartment",
            "identifier_orcid" => "getORCIDResult",
            "identifier_email" => "lookupEmail",
            "summary_degrees" => "getDegrees",
            "summary_primary_dept" => "getPrimaryDepartment",
            "summary_gender" => "getGender",
            "summary_sexual_orientation" => "getSexualOrientation",
            "summary_transgender" => "getTransgenderStatus",
            "summary_race_ethnicity" => "getRaceEthnicity",
            "summary_dob" => "getDOB",
            "summary_citizenship" => "getCitizenship",
            "summary_current_institution" => "getInstitution",
            "summary_current_division" => "getCurrentDivision",
            "identifier_left_division" => "getCurrentDivision",    // deliberate duplicate
            "summary_current_rank" => "getCurrentRank",
            "summary_current_start" => "getCurrentAppointmentStart",
            "summary_current_tenure" => "getTenureStatus",
            "summary_urm" => "getURMStatus",
            "summary_wos_h_index" => "getWoSHIndex",
            "summary_icite_h_index" => "getiCiteHIndex",
            "summary_scopus_h_index" => "getScopusHIndex",
            "summary_disability" => "getDisabilityStatus",
            "summary_disadvantaged" => "getDisadvantagedStatus",
            "summary_training_start" => "getTrainingStart",
            "summary_training_end" => "getTrainingEnd",
            "summary_mentor" => "getMentorText",
            "summary_mentor_userid" => "getMentorUserid",
            "identifier_ecommons_id" => "getEcommonsId",
        ];
		if (in_array("summary_all_degrees", $metadataFields)) {
		    Application::log("Adding summary_all_degrees");
		    $ary["summary_all_degrees"] = "checkAllDegrees";
        }
        for ($i = 1; $i <= self::getNumStudySections(); $i++) {
            $ary["summary_study_section_name_".$i] = "getStudySection".$i;
            $ary["summary_other_standing_".$i] = "getStudySectionOther".$i;
        }
        if (in_array("identifier_userid", $metadataFields)) {
            $ary["identifier_userid"] = "lookupUserid";
        } else {
            $ary["identifier_vunet"] = "lookupVUNet";
        }

		return $ary;
	}


	private function getTrainingStart($rows) {
        $result = $this->getGenericValueForField($rows, "summary_training_start");
		$fieldName = $result->getField();
        // Application::log("getTrainingStart found result in $fieldName");
        if (preg_match("/^promotion_/", $fieldName)) {
			$positionChanges = self::getOrderedPromotionRows($rows);
			$trainingRanks = array(9, 10);
			foreach ($positionChanges as $startTs => $row) {
				if ($row['promotion_rank'] && in_array($row['promotion_rank'], $trainingRanks) && $row['promotion_in_effect']) {
					return new Result($row['promotion_in_effect'], "manual", "", "", $this->pid);
				}
			}
			return new Result("", "");   // undecipherable
        }
		return $result;
	}

	private function getDoctoralRowsFromDegrees($rows) {
	    $doctoralRegexes = self::getDoctoralRegexes();
	    $choices = self::getChoices($this->metadata);
	    $field = "imported_degree";
	    $rowsToReturn = [];
	    foreach ($rows as $row) {
	        if ($row[$field] && ($row['redcap_repeat_instrument'] == "manual_degree")) {
	            $degree = $choices[$field][$row[$field]];
                foreach ($doctoralRegexes as $regex) {
                    if (preg_match($regex, $degree)) {
                        $rowsToReturn[] = $row;
                    }
                }
            }
        }
	    return $rowsToReturn;
    }

	public static function getAwardTypeFields($metadata) {
	    return REDCapManagement::getFieldsWithRegEx($metadata, "/^summary_award_type_/");
    }

	public static function getAwardDateFields($metadata) {
        return REDCapManagement::getFieldsWithRegEx($metadata, "/^summary_award_.*date_/");
    }

	private static function getOrderedPromotionRows($rows) {
		$changes = array();
		$startField = "promotion_in_effect";
		foreach ($rows as $row) {
			if (($row['redcap_repeat_instrument'] == "position_change") && $row[$startField]) {
				$changes[strtotime($row[$startField])] = $row;
			}
		}

		krsort($changes);    // get most recent
		return $changes;
	}

	private function getTrainingEnd($rows) {
        $result = $this->getGenericValueForField($rows, "summary_training_end");
		$fieldName = $result->getField();
		// Application::log("getTrainingEnd found result in $fieldName");
		if (preg_match("/^promotion_/", $fieldName)) {
			$positionChanges = self::getOrderedPromotionRows($rows);
			$trainingRanks = array(9, 10);
			$trainingStart = FALSE;
			foreach ($positionChanges as $startTs => $row) {
				if ($row['promotion_rank'] && in_array($row['promotion_rank'], $trainingRanks) && $row['promotion_in_effect']) {
					$trainingStart = $startTs;
				}
			}
			if ($trainingStart) {
				$nextStart = "";
				foreach ($positionChanges as $startTs => $row) {
					if ($startTs == $trainingStart) {
						if ($nextStart) {
							return new Result($nextStart, "manual", "", "", $this->pid);
						}
					}
					$nextStart = $row['promotion_in_effect'];
				}
			}
			return new Result("", "");   // undecipherable
		}
		return $result;
	}

	private function checkForScopusError($data) {
	    if (isset($data["service-error"]) && isset($data["service-error"]["status"])) {
            if (isset($data["service-error"]["status"]["statusText"])) {
                Application::log("ERROR: ".$data["service-error"]["status"]["statusText"], $this->pid);
            } else if (isset($data["service-error"]["status"]["statusCode"])) {
                Application::log("ERROR: ".$data["service-error"]["status"]["statusCode"], $this->pid);
            } else {
                Application::log("ERROR: Could not parse ".json_encode($data), $this->pid);
            }
            return TRUE;
        }
	    return FALSE;
    }

    private function getScopusHIndex($rows) {
        if ($key = Application::getSetting("scopus_api_key", $this->pid)) {
            $format = "application/json";
            if ($orcid = $this->getORCID()) {
                $url = "https://api.elsevier.com/content/author/orcid/$orcid?httpAccept=" . urlencode($format) . "&apikey=" . $key;
                list($resp, $json) = REDCapManagement::downloadURL($url, $this->pid);
                $data = json_decode($json, TRUE);
                if ($this->checkForScopusError($data)) {
                    return new Result("", "");
                } else {
                    foreach ($data["author-retrieval-response"] as $authorRow) {
                        if ($authorRow['h-index']) {
                            return new Result($authorRow['h-index'], "");
                        }
                    }
                }
            } else {
                $firstNames = NameMatcher::explodeFirstName($this->getName("first"));
                $lastNames = NameMatcher::explodeLastName($this->getName("last"));
                $institutions = $this->getAllOtherInstitutions($rows);
                foreach ($firstNames as $firstName) {
                    foreach ($lastNames as $lastName) {
                        foreach ($institutions as $institution) {
                            $query = "AUTHFIRST($firstName) AND AUTHLASTNAME($lastName) AND AFFIL($institution)";
                            $url = "https://api.elsevier.com/content/search/author?httpAccept=" . urlencode($format) . "&query=" . urlencode($query) . "&apikey=" . $key;
                            list($resp, $json) = REDCapManagement::downloadURL($url, $this->pid);
                            $data = json_decode($json, TRUE);
                            if ($this->checkForScopusError($data)) {
                                return new Result("", "");
                            } else if ($data['search-results']) {
                                foreach ($data['search-results']['entry'] as $authorRow) {
                                    if ($authorRow['dc:identifier']) {
                                        $authorId = preg_replace("/^AUTHOR_ID:/", "", $authorRow['dc:identifier']);
                                        if ($authorId) {
                                            break;
                                        }
                                    }
                                }
                                if ($authorId) {
                                    $url = "http://api.elsevier.com/content/author_id/" . $authorId . "?view=metrics&httpAccept=" . urlencode($format) . "&apikey=" . $key;
                                    list($resp, $json) = REDCapManagement::downloadURL($url, $this->pid);
                                    $data = json_decode($json, TRUE);
                                    foreach ($data["author-retrieval-response"] as $authorRow) {
                                        if ($authorRow['h-index']) {
                                            return new Result($authorRow['h-index'], "");
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return new Result("", "");
    }

    private function getWoSHIndex($rows) {
        return $this->getHIndex($rows, "citation_wos_times_cited");
    }

    private function getiCiteHIndex($rows) {
        return $this->getHIndex($rows, "citation_num_citations");
    }

    private function getHIndex($rows, $timesCitedField) {
	    $timesCitedValues = [];
        foreach ($rows as $row) {
            if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_include'] == 1) && $row[$timesCitedField]) {
                $timesCitedValues[] = $row[$timesCitedField];
            }
        }
        if (!empty($timesCitedValues)) {
            $i = 0;
            do {
                $i++;
                $numValid = 0;
                foreach ($timesCitedValues as $value) {
                    if ($value >= $i) {
                        $numValid++;
                    }
                }
            } while ($i < count($timesCitedValues) && ($numValid >= $i));
            $i--;
            return new Result($i, "");
        }
        return new Result("", "");
    }

	private function getURMStatus($rows) {
		$raceEthnicityValue = $this->getRaceEthnicity($rows)->getValue();
		$disadvValue = $this->getDisadvantagedStatus($rows)->getValue();
		$disabilityValue = $this->getDisabilityStatus($rows)->getValue();

		$minorities = array(2, 3, 4, 6, 8, 9, 10);
		$value = "0";
		if (($raceEthnicityValue === "") && ($disadvValue === "") && ($disabilityValue === "")) {
			$value = "";
		}
		if (in_array($raceEthnicityValue, $minorities)) {
			$value = "1";
		}
		if ($disadvValue == "1") {
			$value = "1";
		}
		if ($disabilityValue == "1") {
			$value = "1";
		}
		return new Result($value, "", "", "", $this->pid);
	}

	private function getDisadvantagedStatus($rows) {
		$vars = self::getDefaultOrder("summary_disadvantaged");
        $vars = $this->getOrder($vars, "summary_disadvantaged");
        $result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		if ($result->getValue() == 1) {
			# Yes
			$value = "1";
		} else if ($result->getValue() == 2) {
			# No
			$value = "0";
		} else {
			$value = "";
		}
		$result->setValue($value);
		return $result;
	}

	private function getDisabilityStatus($rows) {
		$vars = self::getDefaultOrder("summary_disability");
        $vars = $this->getOrder($vars, "summary_disability");
        $result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		if ($result->getValue() == 1) {
			# Yes
			$value = "1";
		} else if ($result->getValue() == 2) {
			# No
			$value = "0";
		} else {
			$value = "";
		}
		$result->setValue($value);
		return $result;
	}

	private function processDemographics() {
		$this->demographics = array();
		$fields = self::getDemographicFields($this->metadata);
		$rows = $this->rows;

		$metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);

		$specialCases = array("summary_degrees", "summary_coeus_name", "summary_survey", "summary_race_ethnicity", "summary_all_degrees");
		foreach ($fields as $field => $func) {
			if (in_array($field, $metadataFields)) {
				if (in_array($field, $specialCases)) {
					# special cases
					if (($field == "summary_degrees") || ($field == "summary_survey") || ($field == "summary_coeus_name")) {
						$result = $this->$func($rows);
						$this->demographics[$field] = $result->getValue();
					} else if ($field == "summary_race_ethnicity") {
						$result = $this->$func($rows);
	
						$this->demographics[$field] = $result->getValue();
						$this->demographics["summary_race_source"] = $result->getRaceSource();
						$this->demographics["summary_race_sourcetype"] = $result->getRaceSourceType();
						$this->demographics["summary_ethnicity_source"] = $result->getEthnicitySource();
						$this->demographics["summary_ethnicity_sourcetype"] = $result->getEthnicitySourceType();
					} else if ($field == "summary_all_degrees") {
					    $results = $this->$func($rows);
					    foreach ($results as $checkboxField => $result) {
					        $this->demographics[$checkboxField] = $result->getValue();
                        }
                    }
				} else {
					$result = $this->$func($rows);
					if (is_array($result)) {
					    $results = $result;
					    foreach ($results as $resultField => $result) {
                            $this->demographics[$resultField] = $result->getValue();
                        }
                    } else {
                        $this->demographics[$field] = $result->getValue();
                        $this->demographics[$field."_source"] = $result->getSource();
                        $this->demographics[$field."_sourcetype"] = $result->getSourceType();
                    }
				}
			}
			# no else because they probably have not updated their metadata
		}
	}

	public function getDemographic($demo) {
		if (!preg_match("/^summary_/", $demo)) {
			$demo = "summary_".$demo;
		}
		$choices = self::getChoices($this->metadata);
		if (isset($this->demographics[$demo])) {
			if (isset($choices[$demo]) && isset($this->demographics[$demo])) {
				return $choices[$demo][$this->demographics[$demo]];
			} else {
				return $this->demographics[$demo];
			}
		}
		return "";
	}

	private function initGrants() {
		$grants = new Grants($this->token, $this->server);
		if (iset($this->rows)) {
			$grants->setRows($this->rows);
			$grants->compileGrants();
			$this->grants = $grants;
		}
	}

	public static function getExampleField() {
		return "identifier_left_date_source";
	}

	private $token;
	private $server;
	private $metadata;
	private $grants;
	private $rows;
	private $recordId;
	private $name = array();
	private $demographics = array();    // key for demographics is REDCap field name; value is REDCap value
	private $metaVariables = array();   // copied from the Grants class
	private static $choices;
    protected static $skipJobs = ["Student Expense Only", ""];
}

class Result {
	public function __construct($value, $source, $sourceType = "", $date = "", $pid = "") {
		$this->value = $value;
		$this->source = self::translateSourceIfNeeded($source);
		$this->sourceType = $sourceType;
		$this->date = $date;
		$this->pid = $pid;
		$this->field = "";
		$this->instance = "";
	}

	public function displayInText() {
	    $properties = [];
	    $properties[] = "value='".$this->value."'";
	    if ($this->source) {
	        $properties[] = "source=".$this->source;
        }
	    if ($this->sourceType) {
	        $properties[] = "sourceType=".$this->sourceType;
        }
	    if ($this->date) {
	        $properties[] = "date=".$this->date;
        }
	    if ($this->field) {
	        $properties[] = "field=".$this->field;
        }
	    if ($this->instance) {
	        $properties[] = "instance=".$this->instance;
        }
	    if ($this->pid) {
	        $properties[] = "pid=".$this->pid;
        }
	    return implode("; ", $properties);
    }

	public function setInstance($instance) {
		$this->instance = $instance;
	}

	public function getInstance() {
		return $this->instance;
	}

	public function setField($field) {
		$this->field = $field;
	}

	public function getField() {
		return $this->field;
	}

	public function setValue($val) {
		$this->value = $val;
	}

	public function getValue() {
		return $this->value;
	}

	public function getSource() {
		return $this->source;
	}

	public function getSourceType() {
		if (!$this->sourceType) {
			$this->sourceType = self::calculateSourceType($this->source, $this->pid);
		}
		return $this->sourceType;
	}

	public function getDate() {
		return $this->date;
	}

	# returns index from source's choice array
	protected static function translateSourceIfNeeded($source) {
		$sourceChoices = Scholar::getSourceChoices();
		foreach ($sourceChoices as $index => $label) {
			if (($label == $source) || ($index == $source)) {
				return $index;
			}
		}
		return "";
	}

	public static function calculateSourceType($source, $pid = "") {
		$selfReported = array("scholars", "followup", "vfrs");
		$newman = array( "data", "sheet2", "demographics", "new2017", "k12", "nonrespondents", "manual" );

		if ($source == "") {
			$sourcetype = "";
		} else if (in_array($source, $selfReported)) {
            $sourcetype = "1";
        } else if ($pid && in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "1", $pid))) {
            $sourcetype = "1";
		} else if (in_array($source, $newman)) {
            $sourcetype = "2";
        } else if ($pid && in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "2", $pid))) {
			$sourcetype = "2";
		} else {
			$sourcetype = "0";
		}

		return $sourcetype;
	}

	protected $value;
	protected $source;
	protected $sourceType;
	protected $date;
	protected $field;
	protected $instance;
	protected $pid;
}

class RaceEthnicityResult extends Result {
	public function __construct($value, $raceSource, $ethnicitySource, $pid = "") {
		$this->value = $value;
		$this->raceSource = self::translateSourceIfNeeded($raceSource);
		$this->ethnicitySource = self::translateSourceIfNeeded($ethnicitySource);
		$this->pid = $pid;
	}

	public function getRaceSource() {
		return $this->raceSource;
	}

	public function getEthnicitySource() {
		return $this->ethnicitySource;
	}

	public function getRaceSourceType() {
		return self::calculateSourceType($this->raceSource, $this->pid);
	}

	public function getEthnicitySourceType() {
		return self::calculateSourceType($this->ethnicitySource, $this->pid);
	}

	private $raceSource;
	private $ethnicitySource;
}

class Results {
	public function __construct() {
		$this->results = array();
		$this->fields = array();
	}

	public function addResult($field, $result) {
		array_push($this->results, $result);
		array_push($this->fields, $field);
	}

	# precondition: count($this->results) == count($this->fields)
	public function getNumberOfResults() {
		return count($this->results);
	}

	public function getField($i) {
		if ($i < $this->getNumberOfResults()) {
			return $this->fields[$i];
		}
		return "";
	}

	public function getResult($i) {
		if ($i < $this->getNumberOfResults()) {
			return $this->results[$i];
		}
		return NULL;
	}

	private $results;
	private $fields;
}

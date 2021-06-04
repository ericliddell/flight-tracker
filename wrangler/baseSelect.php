<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

define("IMG_SIZE", Citation::getImageSize());

require_once(dirname(__FILE__)."/css.php");
?>
    <script>
        $(document).ready(function() {
            $('#search').keydown(function(e) {
                if ((e.keyCode == 13) || (e.keyCode == 9)) {
                    var page = '<?= $_GET['page'] ?>';
                    var name = $('#search').val();
                    search(page, '#searchDiv', name);
                }
            });
        });

        function resetCitation(id) {
            $('#'+id).val("reset");
            let resetButton = "<?= Application::link("wrangler/reset.php") ?>"
            $('#image_'+id).attr("src", resetButton);
        }

        function selectAllCitations(divSelector) {
            $(divSelector).find("img").each(function(idx, ob) {
                let id = $(ob).attr("id").replace(/^image_/, '');
                $('#'+id).val('include');
                $(ob).attr('src', '<?= Application::link('wrangler/checked.png') ?>');
            });
        }

        function unselectAllCitations(divSelector) {
            $(divSelector).find("img").each(function(idx, ob) {
                let id = $(ob).attr("id").replace(/^image_/, '');
                $('#'+id).val('exclude');
                $(ob).attr('src', '<?= Application::link('wrangler/unchecked.png') ?>');
            });
        }

        function includeCitations(citations) {
            var splitCitations = citations.split(/\n/);
            var pmids = [];
            for (var i = 0; i < splitCitations.length; i++) {
                var citation = splitCitations[i];
                if (citation) {
                    var pmid = getPMID(citation);
                    if (pmid) {
                        pmids.push(pmid);
                    }
                }
            }
            if (pmids.length > 0) {
                presentScreen('Saving...');
                $.post('<?= Application::link("wrangler/savePubs.php") ?>', { record_id: '<?= $_GET['record'] ?>', pmids: pmids }, function(data) {
                    console.log("saveComplete "+JSON.stringify(data));
                    clearScreen();
                    if (data['error']) {
                        makeNote(data['error']);
                    } else if (data['errors']) {
                        makeNote(data['errors']);
                    } else {
                        makeNote();
                    }
                });
            } else {
                alert("Please specify a citation!");
            }
        }

        function checkSubmitButton(citationSelector, enabledSelector) {
            let citations = $(citationSelector).val();
            if (citations) {
                $(enabledSelector+" button.includeButton").show();
            } else {
                $(enabledSelector+" button.includeButton").hide();
            }
        }

        function includeCitation(citation) {
            if (citation) {
                includeCitations(citation);
            } else {
                alert("Please specify a citation!");
            }
        }

        function resetPatent(id) {
            resetCitation(id);
        }

        function selectAllPatents(divSelector) {
            selectAllCitations(divSelector);
        }

        function unselectAllPatents(divSelector) {
            unselectAllCitations(divSelector);
        }

        function includePatents(patents) {
            let splitPatents = patents.split(/\n/);
            let numbers = [];
            for (let i = 0; i < splitPatents.length; i++) {
                let patent = splitPatents[i];
                if (patent) {
                    let number = getPatentNumber(patent);
                    if (number) {
                        numbers.push(number);
                    }
                }
            }
            if (numbers.length > 0) {
                presentScreen("Saving...");
                $.post('<?= Application::link("wrangler/savePatents.php") ?>', { record_id: '<?= $_GET['record'] ?>', numbers: numbers }, function(data) {
                    console.log("Save complete "+JSON.stringify(data));
                    clearScreen();
                    if (data['error']) {
                        makeNote(data['error']);
                    } else if (data['errors']) {
                        makeNote(data['errors']);
                    } else {
                        makeNote();
                    }
                });
            } else {
                alert("Please specify a patent!");
            }
        }

        function includePatent(patent) {
            if (patent) {
                includePatents(patent);
            } else {
                alert("Please specify a patent!");
            }
        }
    </script>
<?php

function getSearch() {
	return Publications::getSearch();
}

function getSelectRecord() {
	return Publications::getSelectRecord();
}

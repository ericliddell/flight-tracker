function stripFromHTML(str, html) {
	html = stripHTML(html);
	var lines = html.split(/\n/);
	var regex = new RegExp(str+":\\s+(.+)$", "i");
	var matches;
	for (var i=0; i < lines.length; i++) {
		var line = lines[i];
		if (matches = line.match(regex)) {
			if (matches[1]) {
				return matches[1];
			}
		}
	}
	return "";
}

function turnOffStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { 'redcap_csrf_token': getCSRFToken(), turn_off: 1 }, function(html) {
		console.log("Turned off "+html);
		$("#status").html("Off");
		$("#status_link").html("Turn on status cron");
		$("#status_link").attr("onclick", "turnOnStatusCron();");
	});
}

function turnOnStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { 'redcap_csrf_token': getCSRFToken(), turn_on: 1 }, function(html) {
		console.log("Turned on "+html);
		$("#status").html("On");
		$("#status_link").html("Turn off status cron");
		$("#status_link").attr("onclick", "turnOffStatusCron();");
	});
}

function trimPeriods(str) {
	return str.replace(/\.$/, "");
}

function submitPMC(pmc, textId, prefixHTML) {
	submitPMCs([pmc], textId, prefixHTML);
}

function resetCitationList(textId) {
	if (isContainer(textId)) {
		$(textId).html('');
	} else {
		$(textId).val('');
	}
	updateButtons(textId);
}

function submitPMCs(pmcs, textId, prefixHTML) {
	if (!Array.isArray(pmcs)) {
		pmcs = pmcs.split(/\n/);
	}
	pmcs = clearOutBlanks(pmcs);
	if (pmcs && Array.isArray(pmcs)) {
		resetCitationList(textId);
		if (pmcs.length > 0) {
			presentScreen("Downloading...");
			downloadOnePMC(0, pmcs, textId, prefixHTML);
		}
	}
}

function downloadOnePMC(i, pmcs, textId, prefixHTML) {
	var pmc = pmcs[i];
	if (pmc) {
		if (!pmc.match(/PMC/)) {
			pmc = 'PMC' + pmc;
		}
		var url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pmc&retmode=xml&id=' + pmc;
		$.ajax({
			url: url,
			success: function (xml) {
				var pmid = '';
				var myPmc = '';
				var articleLocation = 'pmc-articleset>article>front>';
				var articleMetaLocation = articleLocation + 'article-meta>';
				$(xml).find(articleMetaLocation + 'article-id').each(function () {
					if ($(this).attr('pub-id-type') === 'pmid') {
						pmid = 'PubMed PMID: ' + $(this).text() + '. ';
					} else if ($(this).attr('pub-id-type') === 'pmc') {
						myPmc = 'PMC' + $(this).text() + '.';
					}
				});
				var journal = '';
				$(xml).find(articleLocation + 'journal-meta>journal-id').each(function () {
					if ($(this).attr('journal-id-type') === 'iso-abbrev') {
						journal = $(this).text();
					}
				});
				journal = journal.replace(/\.$/, '');

				var year = '';
				var month = '';
				var day = '';
				$(xml).find(articleMetaLocation + 'pub-date').each(function () {
					var pubType = $(this).attr('pub-type');
					if ((pubType === 'collection') || (pubType === 'ppub')) {
						if ($(this).find('month')) {
							month = $(this).find('month').text();
						}
						if ($(this).find('year')) {
							year = $(this).find('year').text();
						}
						if ($(this).find('day')) {
							day = ' ' + $(this).find('day').text();
						}
					}
				});
				var volume = $(xml).find(articleMetaLocation + 'volume').text();
				var issue = $(xml).find(articleMetaLocation + 'issue').text();

				var fpage = $(xml).find(articleMetaLocation + 'fpage').text();
				var lpage = $(xml).find(articleMetaLocation + 'lpage').text();
				var pages = '';
				if (fpage && lpage) {
					pages = fpage + '-' + lpage;
				}

				var title = $(xml).find(articleMetaLocation + 'title-group>article-title').text();
				title = title.replace(/\.$/, '');

				var namePrefix = 'name>';
				var names = [];
				$(xml).find(articleMetaLocation + 'contrib-group>contrib').each(function (index, elem) {
					if ($(elem).attr('contrib-type') === 'author') {
						var surname = $(elem).find(namePrefix + 'surname').text();
						var givenNames = $(elem).find(namePrefix + 'given-names').text();
						names.push(surname + ' ' + givenNames);
					}
				});

				var loc = getLocation(volume, issue, pages);
				var citation = names.join(',') + '. ' + title + '. ' + journal + '. ' + year + ' ' + month + day + ';' + loc + '. ' + pmid + myPmc;
				updateCitationList(textId, prefixHTML, citation);
				let nextI = i + 1;
				if (nextI < pmcs.length) {
					setTimeout(function () {
						downloadOnePMC(nextI, pmcs, textId, prefixHTML);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			},
			error: function (e) {
				updateCitationList(textId, prefixHTML, 'ERROR: ' + JSON.stringify(e));
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMC(nextI, pmcs, textId, prefixHTML);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	}
}

function updateCitationList(textId, prefixHTML, text) {
	var citations = getPreviousCitations(textId, prefixHTML);
	citations.push(prefixHTML+text);
	if (isContainer(textId)) {
		$(textId).html(citations.join('<br>\n'));
	} else {
		$(textId).val(citations.join('\n'));
	}
	updateButtons(textId);
}

function updateButtons(textId) {
	if ($(textId).val()) {
		$('.list button.includeButton').show();
		$('.oneAtATime button.includeButton').show();
	} else {
		$('.list button.includeButton').hide();
		$('.oneAtATime button.includeButton').hide();
	}
}

function getLocation(volume, issue, pages) {
	var loc = volume;
	if (issue) {
		loc += '('+issue+')';
	}
	if (pages) {
		loc += ':'+pages;
	}
	return loc;
}

function submitPMID(pmid, textId, prefixHTML, cb) {
	submitPMIDs([pmid], textId, prefixHTML, cb);
}

function downloadOnePMID(i, pmids, textId, prefixHTML, doneCb) {
	var pmid = pmids[i];
	if (pmid) {
		var url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id='+pmid;
		// AJAX call will return in uncertain order => append, not overwrite, results
		$.ajax({
			url: url,
			success: function(xml) {
				// similar to publications/getPubMedByName.php
				// make all changes in two places in two languages!!!

				var citationLocation = 'PubmedArticleSet>PubmedArticle>MedlineCitation>';
				var articleLocation = citationLocation + 'Article>';
				var journalLocation = articleLocation + 'Journal>JournalIssue>';

				var myPmid = $(xml).find(citationLocation+'PMID').text();
				var year = $(xml).find(journalLocation+'PubDate>Year').text();
				var month = $(xml).find(journalLocation+'PubDate>Month').text();
				var volume = $(xml).find(journalLocation+'Volume').text();
				var issue = $(xml).find(journalLocation+'Issue').text();
				var pages = $(xml).find(articleLocation+'Pagination>MedlinePgn').text();
				var title = $(xml).find(articleLocation+'ArticleTitle').text();
				title = title.replace(/\.$/, '');

				var journal = trimPeriods($(xml).find(articleLocation + 'Journal>ISOAbbreviation').text());
				journal = journal.replace(/\.$/, '');

				var dayNode = $(xml).find(journalLocation+'PubDate>Day');
				var day = '';
				if (dayNode) {
					day = ' '+dayNode.text();
				}

				var names = [];
				$(xml).find(articleLocation+'AuthorList>Author').each(function(index, elem) {
					var lastName = $(elem).find('LastName');
					var initials = $(elem).find('Initials');
					var collective = $(elem).find('CollectiveName');
					if (lastName && initials) {
						names.push(lastName.text()+' '+initials.text());
					} else if (collective) {
						names.push(collective.text());
					}
				});

				var loc = getLocation(volume, issue, pages);
				var citation = names.join(',')+'. '+title+'. '+journal+'. '+year+' '+month+day+';'+loc+'. PubMed PMID: '+myPmid;
				console.log('citation: '+citation);

				updateCitationList(textId, prefixHTML, citation);
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function() {
						downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
					}, 1000);    // rate limiter
				} else if (nextI === pmids.length) {
					clearScreen();
					doneCb();
				}
			},
			error: function(e) {
				updateCitationList(textId, prefixHTML, 'ERROR: '+JSON.stringify(e));
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	}
}

function clearOutBlanks(ary) {
	var ary2 = [];
	for (var i = 0; i < ary.length; i++) {
		if (ary[i]) {
			ary2.push(ary[i]);
		}
	}
	return ary2;
}

// cb = callback
function submitPMIDs(pmids, textId, prefixHTML, cb) {
	if (!Array.isArray(pmids)) {
		pmids = pmids.split(/\n/);
	}
	if (!prefixHTML) {
		prefixHTML = '';
	}
	if (!cb) {
		cb = function() { };
	}
	pmids = clearOutBlanks(pmids);
	if (pmids && (Array.isArray(pmids))) {
		resetCitationList(textId);
		if (pmids.length > 0) {
			presentScreen("Downloading...");
			downloadOnePMID(0, pmids, textId, prefixHTML, cb);
		}
	}
}

function getPreviousCitations(textId, prefixHTML) {
	var citations = [];
	if (isContainer(textId)) {
		citations = $(textId).html().split(/<br>\n/);
		if ((citations.length === 0) && (prefixHTML !== "")) {
			citations.push(prefixHTML);
		}
	} else {
		citations = $(textId).val().split(/\n/);
	}

	var filteredCitations = [];
	for (var i = 0; i < citations.length; i++) {
		if (citations[i]) {
			filteredCitations.push(citations[i]);
		}
	}
	return filteredCitations;
}

function isContainer(id) {
	var containers = ["p", "div"];
	var idTag = $(id).prop("tagName");
	if (containers.indexOf(idTag.toLowerCase()) >= 0) {
		return true;
	} else {
		return false;
	}
}

// returns PHP timestamp: number of seconds (not milliseconds)
function getPubTimestamp(citation) {
	if (!citation) {
		return 0;
	}
	var nodes = citation.split(/[\.\?]\s+/);
	var date = "";
	var i = 0;
	var issue = "";
	while (!date && i < nodes.length) {
		if (nodes[i].match(/;/) && nodes[i].match(/\d\d\d\d.*;/)) {
			var a = nodes[i].split(/;/);
			date = a[0];
			issue = a[1];
		}       
		i++;   
	}       
	if (date) {
		var dateNodes = date.split(/\s+/);

		var year = dateNodes[0];
		var month = "";
		var day = "";

		var months = { "Jan": "01", "Feb": "02", "Mar": "03", "Apr": "04", "May": "05", "Jun": "06", "Jul": "07", "Aug": "08", "Sep": "09", "Oct": "10", "Nov": "11", "Dec": "12" };

		if (dateNodes.length == 1) {
			month = "01";
		} else if (!isNaN(dateNodes[1])) {
			month = dateNodes[1];
			if (month < 10) {
				month = "0"+parseInt(month);
			}       
		} else if (months[dateNodes[1]]) {
			month = months[dateNodes[1]];
		} else {
			month = "01";
		}       
		
		if (dateNodes.length <= 2) {
			day = "01";
		} else {
			day = dateNodes[2];
			if (day < 10) {
				day = "0"+parseInt(day);
			}       
		}       
		var datum = new Date(Date.UTC(year,month,day,'00','00','00'));
		return datum.getTime()/1000;
	} else {
		return 0;
	}
}

function stripOptions(html) {
	return html.replace(/<option[^>]*>[^<]*<\/option>/g, "");
}

function stripBolds(html) {
	return html.replace(/<b>.+<\/b>/g, "");
}

function stripButtons(html) {
	return html.replace(/<button.+<\/button>/g, "");
}

function refreshHeader() {
	var numCits = $("#center div.notDone").length;
	if (numCits == 1) {
		$(".newHeader").html(numCits + " New Citation");
	} else if (numCits === 0) {
		$(".newHeader").html("No New Citations");
	} else {
		$(".newHeader").html(numCits + " New Citations");
	}
}

function sortCitations(html) {
	var origCitations = html.split(/\n/);
	var timestamps = [];
	for (var i = 0; i < origCitations.length; i++) {
		timestamps[i] = getPubTimestamp(stripHTML(stripBolds(stripButtons(origCitations[i]))));
	}
	for (var i = 0; i < origCitations.length; i++) {
		var citationI = origCitations[i];
		var tsI = timestamps[i];
		for (j = i; j < origCitations.length; j++) {
			var citationJ = origCitations[j];
			var tsJ = timestamps[j];
			if (tsI < tsJ) {
				// switch
				origCitations[j] = citationI;
				origCitations[i] = citationJ;

				citationI = citationJ;
				tsI = tsJ;
				// Js will be reassigned with the next iteration of the j loop
			}
		}
	}
	return origCitations.join("\n")+"\n";
}

function stripHTML(str) {
	return str.replace(/<[^>]+>/g, "");
}

function removeThisElem(elem) {
	$(elem).remove();
	refreshHeader();
}

function omitCitation(citation) {
	var html = $("#omitCitations").html();
	var citationHTML = "<div class='finalCitation'>"+citation+"</div>";
	html += citationHTML;

	$("#omitCitations").html(sortCitations(html));
}

function getPMID(citation) {
	var matches = citation.match(/PubMed PMID: \d+/);
	if (matches && (matches.length >= 1)) {
		var pmidStr = matches[0];
		var pmid = pmidStr.replace(/PubMed PMID: /, "");
		return pmid;
	}
	return "";
}

function getMaxID() {
	return $("#newCitations .notDone").length;
}

function addCitationLink(citation) {
	return citation.replace(/PubMed PMID: (\d+)/, "<a href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$1'>PubMed PMID: $1</a>");
}

function getUrlVars() {
	const vars = {};
	window.location.href.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
		vars[key] = value;
	});
	return vars;
}

function refresh() {
	location.reload();
}

// page is blank if current page is requested
function getPageUrl(page) {
	page = page.replace(/^\//, "");
	var params = getUrlVars();
	if (params['page']) {
		var url = "?pid="+params['pid'];
		if (page) {
			page = page.replace(/\.php$/, "");
			url += "&page="+encodeURIComponent(page);
		} else if (params['page']) {
			url += "&page="+encodeURIComponent(params['page']);
		}
		if (params['prefix']) {
			url += "&prefix="+encodeURIComponent(params['prefix']);
		}
		return url;
	}
	return page;
}

function getHeaders() {
	var params = getUrlVars();
	if (typeof params['headers'] != "undefined") {
		return "&headers="+params['headers'];
	}
	return "";
}

function getPid() {
	var params = getUrlVars();
	if (typeof params['pid'] != "undefined") {
		return params['pid'];
	}
	return "";
}

function makeNote(note) {
	if (typeof note != "undefined") {
		$("#note").html(note);
		if ($("#note").hasClass("green")) {
			$("#note").removeClass("green");
		}
		if (!$("#note").hasClass("red")) {
			$("#note").addClass("red");
		}
	} else {
		if ($("#note").hasClass("red")) {
			$("#note").removeClass("red");
		}
		if (!$("#note").hasClass("green")) {
			$("#note").addClass("green");
		}
		$("#note").html("Save complete! Please <a href='javascript:;' onclick='refresh();'>refresh</a> to see the latest list after you have completed your additions.");
	}
	$("#note").show();
}

// coordinated with Citation::getID in class/Publications.php
function getID(citation) {
	var matches;
	if (matches = citation.match(/PMID: \d+/)) {
		var pmidStr = matches[0];
		return pmidStr.replace(/^PMID: /, "");
	} else {
		return citation;
	}
}

function isCitation(id) {
	if (id) {
		if (id.match(/^PMID/)) {
			return true;
		}
		if (id.match(/^ID/)) {
			return true;
		}
	}
	return false;
}

function isOriginal(id) {
	if (id) {
		if (id.match(/^ORIG/)) {
			return true;
		}
	}
	return false;
}

function getRecord() {
	var params = getUrlVars();
	var recordId = params['record'];
	if (typeof recordId == "undefined") {
		return 1;
	}
	return recordId;
}

function submitChanges(nextRecord) {
	const recordId = getRecord();
	const newFinalized = [];
	const newOmits = [];
	const resets = [];
	$('#finalize').hide();
	$('#uploading').show();
	let type = "";
	$('[type=hidden]').each(function(idx, elem) {
		const id = $(elem).attr("id");
		if ((typeof id != "undefined") && id.match(/^PMID/)) {
			type = "Publications";
			const value = $(elem).val();
			const pmid = id.replace(/^PMID/, "");
			if (!isNaN(pmid)) {
				if (value === "include") {
					// checked => put in finalized
					newFinalized.push(pmid);
				} else if (value === "exclude") {
					// unchecked => put in omits
					newOmits.push(pmid);
				} else if (value === "reset") {
					resets.push(pmid);
				}
			}
		} else 	if ((typeof id != "undefined") && id.match(/^USPO/)) {
			type = "Patents";
			const value = $(elem).val();
			const patentNumber = id.replace(/^USPO/, "");
			if (!isNaN(patentNumber)) {
				if (value === "include") {
					// checked => put in finalized
					newFinalized.push(patentNumber);
				} else if (value === "exclude") {
					// unchecked => put in omits
					newOmits.push(patentNumber);
				} else if (value === "reset") {
					resets.push(patentNumber);
				}
			}
		}
	});

	let url = "";
	if (type === "Patents") {
		url = getPageUrl("wrangler/savePatents.php");
	} else if (type === "Publications") {
		url = getPageUrl("wrangler/savePubs.php");
	}
	if (url) {
		const postdata = {
			record_id: recordId,
			omissions: JSON.stringify(newOmits),
			resets: JSON.stringify(resets),
			finalized: JSON.stringify(newFinalized),
			redcap_csrf_token: getCSRFToken()
		};
		console.log('Posting '+JSON.stringify(postdata));
		const params = getUrlVars();
		let wranglerType = "";
		if (params['wranglerType']) {
			wranglerType = '&wranglerType='+params['wranglerType'];
		}
		$.ajax({
			url: url,
			type: 'POST',
			data: postdata,
			success: function(data) {
				if (data['count'] && (data['count'] > 0)) {
					const mssg = makeWranglingMessage(data['count']);
					window.location.href = getNextWranglingUrl(mssg, nextRecord, wranglerType);
				} else if (data['item_count'] && (data['item_count'] > 0)) {
					const mssg = makeWranglingMessage(data['item_count']);
					window.location.href = getNextWranglingUrl(mssg, nextRecord, wranglerType);
				} else if (data['error']) {
					$('#uploading').hide();
					$('#finalize').show();
					$.sweetModal({
						content: 'ERROR: '+data['error'],
						icon: $.sweetModal.ICON_ERROR
					});
				} else {
					$('#uploading').hide();
					$('#finalize').show();
					console.log("Unexplained return value. "+JSON.stringify(data));
				}
			},
			error: function(e) {
				if (!e.status || (e.status !== 200)) {
					$('#uploading').hide();
					$('#finalize').show();
					$.sweetModal({
						content: 'ERROR: '+JSON.stringify(e),
						icon: $.sweetModal.ICON_ERROR
					});
				} else {
					console.log(JSON.stringify(e));
					if (e.status === 200) {
						const mssg = "Upload successful.";
						window.location.href = getNextWranglingUrl(mssg, nextRecord, wranglerType);
					}
				}
			}
		});
	}
	console.log("Done");
}

function makeWranglingMessage(cnt) {
	let str = "items";
	if (cnt === 1) {
		str = "item";
	}
	return cnt+" "+str+" uploaded";
}

function getNextWranglingUrl(mssg, nextRecord, wranglerTypeSpecs) {
	return getPageUrl("wrangler/include.php")+getHeaders()+"&mssg="+encodeURI(mssg)+"&record="+nextRecord+wranglerTypeSpecs;
}

function checkSticky() {
	var normalOffset = $('#normalHeader').offset();
	if (window.pageYOffset > normalOffset.top) {
		if (!$('#stickyHeader').is(':visible')) {
			$('#stickyHeader').show();
			$('#stickyHeader').width($('maintable').width()+"px");
			$('#stickyHeader').css({ "left": $('#maintable').offset().left+"px" });
		}
	} else {
		if ($('#stickyHeader').is(':visible')) {
			$('#stickyHeader').hide();
		}
	}
}

function submitOrder(selector, resultsSelector) {
	if ($(resultsSelector).hasClass("green")) {
		$(resultsSelector).removeClass("green");
	}
	if ($(resultsSelector).hasClass("red")) {
		$(resultsSelector).removeClass("red");
	}
	$(resultsSelector).addClass("yellow");
	$(resultsSelector).html("Processing...");
	$(resultsSelector).show();

	var keys = new Array();
	$(selector+" li").each(function(idx, ob) {
		var id = $(ob).attr("id");
		keys.push(id);
	});
	if (keys.length > 0) {
		$.post(getPageUrl("lexicallyReorder.php"), { 'redcap_csrf_token': getCSRFToken(), keys: JSON.stringify(keys) }, function(data) {
			console.log("Done");
			console.log(data);
			$(resultsSelector).html(data);
			if ($(resultsSelector).hasClass("yellow")) {
				$(resultsSelector).removeClass("yellow");
			}
			if (data.match(/ERROR/)) {
				$(resultsSelector).addClass("red");
			} else {
				$(resultsSelector).addClass("green");
			}
			setTimeout(function() {
				$(resultsSelector).fadeOut();
			}, 5000);
		});
	}
}

function presentScreen(mssg, imageUrl) {
	if ($('#overlayFT').length == 0) {
		$('body').prepend('<div id="overlayFT"></div>');
	}
	if ($('#overlayFT').length > 0) {
		if (!imageUrl) {
			imageUrl = getPageUrl('img/loading.gif');
		}
		$('#overlayFT').html('<br><br><br><br><h1 class=\"warning\">'+mssg+'</h1><p class=\"centered\"><img src=\"'+imageUrl+'\" alt=\"Waiting\"></p>');
		$('#overlayFT').show();
	}
}

function clearScreen() {
	if ($('#overlayFT').length > 0) {
		$('#overlayFT').html('');
		$('#overlayFT').hide();
	}
}

function toggleHelp(helpUrl, helpHiderUrl, currPage) {
	if ($('#help').is(':visible')) {
		hideHelp(helpHiderUrl);
	} else {
		showHelp(helpUrl, currPage);
	}
}

function showHelp(helpUrl, currPage) {
	$.post(helpUrl, { 'redcap_csrf_token': getCSRFToken(), fullPage: currPage }, function(html) {
		if (html) {
			$('#help').html(html);
		} else {
			$('#help').html("<h4 class='nomargin'>No Help Resources are Available for This Page</h4>");
		}
		// coordinate with .subnav
		if ($('.subnav').length == 1) {
			var right = $('.subnav').position().left + $('.subnav').width(); 
			var offset = 10;
			var helpLeft = right + offset;
			var rightOffset = helpLeft + 40;
			var helpWidth = "calc(100% - "+rightOffset+"px)";
			$('#help').css({ left: helpLeft+"px", position: "relative", width: helpWidth });
		} 
		$('#help').slideDown();
	});
}

function hideHelp(helpHiderUrl) {
	$('#help').hide();
	$.post(helpHiderUrl, { 'redcap_csrf_token': getCSRFToken() }, function() {
	});
}

function startTonight() {
	var url = getPageUrl("downloadTonight.php");
	console.log(url);
	$.ajax({
		data: { 'redcap_csrf_token': getCSRFToken() },
		type: 'POST',
		url:url,
		success: function(data) {
			console.log("result: "+data);
			$.sweetModal({
				content: 'Downloads will start tonight.',
				icon: $.sweetModal.ICON_SUCCESS
			});
		},
		error: function(e) {
			console.log("ERROR! "+JSON.stringify(e));
		}
	});
}

// Should only be called by REDCap SuperUser or else will throw errors
// if a pid does not have FlightTracker enabled, it will also cause an error
function installMetadataForProjects(pids) {
	presentScreen("Updating Data Dictionaries...<br>(may take some time)")
	const url = getPageUrl("metadata.php");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "install_all", pids: pids }, function(json) {
		console.log(json);
		if (json.charAt(0) === '<') {
			$.sweetModal({
				content: 'The process did not complete because REDCap requested a login.',
				icon: $.sweetModal.ICON_ERROR
			});

			clearScreen();
		} else {
			const data = JSON.parse(json);
			const numProjects = Object.keys(data).length;
			$.sweetModal({
				content: numProjects+' projects were successfully updated.',
				icon: $.sweetModal.ICON_SUCCESS
			});
			$("#metadataWarning").addClass("install-metadata-box-success");
			$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> Installation Complete");
			setTimeout(function() {
				$("#metadataWarning").fadeOut(500);
			}, 3000);
			clearScreen();
		}
	});
}

function installMetadata(fields) {
	const url = getPageUrl("metadata.php");
	$("#metadataWarning").removeClass("install-metadata-box-danger");
	$("#metadataWarning").addClass("install-metadata-box-warning");
	$("#metadataWarning").html("<em class='fa fa-spinner fa-spin'></em> Installing...");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "install", fields: fields }, function(data) {
		console.log(JSON.stringify(data));
		$("#metadataWarning").removeClass("install-metadata-box-warning");
		if (!data.match(/Exception/)) {
			$("#metadataWarning").addClass("install-metadata-box-success");
			$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> Installation Complete");
			setTimeout(function() {
				$("#metadataWarning").fadeOut(500);
			}, 3000);
		} else {
			$("#metadataWarning").addClass("install-metadata-box-danger");
			$("#metadataWarning").html("Error in installation! Metadata not updated. "+JSON.stringify(data));
		}
	});
}

function checkMetadata(phpTs) {
	var url = getPageUrl("metadata.php");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "check", timestamp: phpTs }, function(html) {
		if (html) {
			$('#metadataWarning').addClass("red");
			$('#metadataWarning').html(html);
		}
	});
}

function submitLogs(url) {
	$.post(url, { 'redcap_csrf_token': getCSRFToken() }, function(data) {
		console.log(data);
		$.sweetModal({
			content: 'Logs emailed to developers.',
			icon: $.sweetModal.ICON_SUCCESS
		});

	});
}

function getNewWranglerImg(state) {
	var validStates = [ "checked", "unchecked", "omitted" ];
	if (state && in_array(state, validStates)) {
		var url = "";
		switch(state) {
			case "checked":
				url = checkedImg;
				break;
			case "unchecked":
				url = uncheckedImg;
				break;
			case "omitted":
				url = omittedImg;
				break;
			default:
				break;
		}
		return url;
	}
	return "";
}

function getPubImgHTML(newState) {
	var newImg = getNewWranglerImg(newState);
	return "<img align='left' style='margin: 2px; width: 26px; height: 26px;' src='"+newImg+"' alt='"+newState+"' onclick='changeCheckboxValue(this);'>";
}

function addPMID(pmid) {
	if (!isNaN(pmid) && notAlreadyUsed(pmid)) {
		var newState = 'checked';
		var newDiv = 'notDone';
		var newId = 'PMID'+pmid;
		$('#'+newDiv).append('<div id="'+newId+'" style="margin: 8px 0; min-height: 26px;"></div>');
		submitPMID(pmid, '#'+newId, getPubImgHTML(newState), function() { if (enqueue()) { $('#'+newDiv+'Count').html(parseInt($('#'+newDiv+'Count').html(), 10) + 1); } });
	} else if (isNaN(pmid)) {
		$.sweetModal({
			content: 'PMID '+pmid+' is not a number!',
			icon: $.sweetModal.ICON_ERROR
		});
	} else {
		// not already used
		var names = {};
		names['finalized'] = 'Citations Already Accepted and Finalized';
		names['notDone'] = 'Citations to Review';
		names['omitted'] = 'Citations to Omit';
		$.sweetModal({
			content: 'PMID '+pmid+' has already been entered in '+names[bin]+'!',
			icon: $.sweetModal.ICON_SUCCESS
		});
	}
}

function changeCheckboxValue(ob) {
	const divId = $(ob).parent().attr("id")
	const state = $(ob).attr('alt')
	const pmid = $(ob).parent().attr('id').replace(/^PMID/, "")
	const recordId = $("#record_id").val()

	const params = getUrlVars()
	const hash = params['s']

	let newState = ""
	let newDiv = ""
	let oldDiv = ""
	switch(state) {
		case "omitted":
			newState = "checked"
			newDiv = "notDone"
			oldDiv = "omitted"
			break
		case "unchecked":
			newState = "checked"
			break
		case "checked":
			newState = "omitted"
			newDiv = "omitted"
			oldDiv = "notDone"
			break
		default:
			break
	}
	const newImg = getNewWranglerImg(newState);
	if (newState) {
		$(ob).attr('alt', newState)
		$.post(getPageUrl("wrangler/certifyPub"), { 'redcap_csrf_token': getCSRFToken(), hash: hash, record: recordId, pmid: pmid, state: newState }, function(html) {
			console.log(html);
		});
	}
	if (newImg) {
		$(ob).attr('src', newImg)
	}
	if (newDiv) {
		const obDiv = $("#"+divId).detach()
		$(obDiv).appendTo("#"+newDiv)
		$(obDiv).show()
		$('#'+newDiv+'Count').html(parseInt($('#'+newDiv+'Count').html(), 10) + 1)
	}
	if (oldDiv) {
		$("#"+oldDiv+"Count").html(parseInt($('#'+oldDiv+'Count').html(), 10) - 1)
	}
	// enqueue();
}

function notAlreadyUsed(pmid) {
	return ($('#PMID'+pmid).length === 0);
}

function enqueue() {
}

function presetValue(name, value) {
	if (($('[name="'+name+'"]').length > 0) && ($('[name="'+name+'"]').val() == "") && (value != "")) {
		$('[name="'+name+'"]').val(value);
		if ($('[name='+name+'___radio]').length > 0) {
			$('[name='+name+'___radio][value='+value+']').attr('checked', true);
		}
	}
}

function clearValue(name) {
	$('[name=\''+name+'\']').val('');
	if ($('[name='+name+'___radio]').length > 0) {
		$('[name='+name+'___radio]').attr('checked', false);
	}
}

function includeWholeRecord(record) {
	$('#include_'+record).hide();
	$('#exclude_'+record).show();
	$('#links_'+record).show();
	$('#note_'+record).hide();
	$('.record_'+record).val(1);
}

function excludeWholeRecord(record) {
	$('#include_'+record).show();
	$('#exclude_'+record).hide();
	$('#links_'+record).hide();
	$('#note_'+record).show();
	$('.record_'+record).val(0);
}

function removePMIDFromAutoApprove(record, instance, pmid) {
	$('#record_'+record+':'+instance).val(0);
	$('#record_'+record+'_idx_'+pmid).hide();
}

function downloadUrlIntoPage(url, selector) {
	let spinnerUrl = getPageUrl("img/loading.gif");
	$(selector).html("<p class='centered'><img src='"+spinnerUrl+"' style='width: 25%;'></p>");
	let startTs = Date.now();
	$.ajax(url, {
		data: { 'redcap_csrf_token': getCSRFToken() },
		type: 'POST',
		success: function(html) {
			let endTs = Date.now();
			console.log("Success: "+((endTs - startTs) / 1000)+" seconds");
			$(selector).html(html);
		},
		error: function (e) {
			console.log("ERROR: "+JSON.stringify(e));
		}
	});
}

function submitEmailAddresses() {
	let selector = 'input[type=checkbox].who_to:checked';
	var checkedEmails = [];
	let post = {};
	post['recipient'] = 'individuals';
	post['name'] = 'Email composed at '+new Date().toUTCString();
	post['noalert'] = '1';
	$(selector).each( function() {
		let name = $(this).attr('name');
		post[name] = 'checked';
		checkedEmails.push(name);
	});
	if (checkedEmails.length > 0) {
		postValues(getPageUrl("emailMgmt/configure.php"), post);
	}
}

function createCohortProject(cohort, src) {
	if (src) {
		$(src).dialog("close");
	}
	presentScreen("Creating project...<br>May take some time to set up project");
	$.post(getPageUrl("cohorts/createCohortProject.php"), { 'redcap_csrf_token': getCSRFToken(), "cohort": cohort }, function(mssg) {
		clearScreen();
		console.log(mssg);
		$.sweetModal({
			content: mssg,
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

// https://stackoverflow.com/questions/133925/javascript-post-request-like-a-form-submit
function postValues(path, parameters) {
	var form = $('<form></form>');
	form.attr("method", "post");
	form.attr("action", path);

	$.each(parameters, function(key, value) {
		var field = $('<input></input>');
		field.attr("type", "hidden");
		field.attr("name", key);
		field.attr("value", value);
		form.append(field);
	});

	// The form needs to be a part of the document in
	// order for us to be able to submit it.
	$(document.body).append(form);
	form.submit();
}

function setupHorizontalScroll(tableWidth) {
	$('.top-horizontal-scroll').scroll(function(){
		$('.horizontal-scroll').scrollLeft($('.top-horizontal-scroll').scrollLeft());
	});
	$('.horizontal-scroll').scroll(function(){
		$('.top-horizontal-scroll').scrollLeft($('.horizontal-scroll').scrollLeft());
	});
	let horScrollWidth = $('.horizontal-scroll').width();
	$('.top-horizontal-scroll').css({ 'width': horScrollWidth });
	$('.top-horizontal-scroll div').css({ 'width': tableWidth });
}

function submitPatent(patent, textId, prefixHTML, cb) {
	submitPatents([patent], textId, prefixHTML, cb);
}

function submitPatents(patents, textId, prefixHTML, cb) {
	if (!Array.isArray(patents)) {
		patents = pmids.split(/\n/);
	}
	if (!prefixHTML) {
		prefixHTML = '';
	}
	if (!cb) {
		cb = function() { };
	}
	if (patents && (Array.isArray(patents))) {
		resetCitationList(textId);
		presentScreen("Downloading...");
		downloadOnePatent(0, patents, textId, prefixHTML, cb);
	}
}

function downloadOnePatent(i, patents, textId, prefixHTML, doneCb) {
	const patentNumber = patents[i].replace(/^US/i, '');
	if (patentNumber) {
		const o = {"page": 1, "per_page": 50};
		const q = {"patent_number": patentNumber };
		const f = ["patent_number", "patent_date", "patent_title"];

		const url = "https://api.patentsview.org/patents/query?q="+JSON.stringify(q)+"&f="+JSON.stringify(f)+"&o="+JSON.stringify(o);
		// AJAX call will return in uncertain order => append, not overwrite, results
		$.ajax({
			url: url,
			success: function(data) {
				console.log(JSON.stringify(data));
				const listings = [];
				for (let i=0; i < data.patents.length; i++) {
					const entry = data.patents[i];
					if (entry['patent_number']) {
						const listing = "Patent "+entry['patent_number']+' '+entry['patent_title']+' ('+entry['patent_date']+')';
						listings.push(listing);
					}
				}

				updatePatentList(textId, prefixHTML, listings.join('\n'));
				const nextI = i + 1;
				if (nextI < patents.length) {
					setTimeout(function() {
						downloadOnePatent(nextI, patents, textId, prefixHTML, doneCb);
					}, 500);    // rate limiter
				} else if (nextI === patents.length) {
					clearScreen();
					doneCb();
				}
			},
			error: function(e) {
				updatePatentList(textId, prefixHTML, 'ERROR: '+JSON.stringify(e));
				const nextI = i + 1;
				if (nextI < patents.length) {
					setTimeout(function () {
						downloadOnePatent(nextI, patents, textId, prefixHTML, doneCb);
					}, 500);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	}
}

function getPatentNumber(patent) {
	const matches = patent.match(/Patent \d+/);
	if (matches && (matches.length >= 1)) {
		const str = matches[0];
		return str.replace(/Patent /, '').replace(/^US/i, '');
	}
	return '';
}

function updatePatentList(textId, prefixHTML, text) {
	updateCitationList(textId, prefixHTML, text);
}

function omitPublication(recordId, instance, pmid) {
	presentScreen('Omitting');
	$.post(getPageUrl('publications/omit.php'), { 'redcap_csrf_token': getCSRFToken(), record: recordId, instance: instance, pmid: pmid }, function(html) {
		clearScreen();
		console.log(html);
		$.sweetModal({
			content: 'Publication successfully omitted!',
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

function omitGrant(recordId, grantNumber, source) {
	presentScreen('Omitting');
	$.post(getPageUrl('wrangler/omitGrant.php'), { 'redcap_csrf_token': getCSRFToken(), record: recordId, grantNumber: grantNumber, source: source }, function(html) {
		clearScreen();
		console.log(html);
		$.sweetModal({
			content: 'Grant successfully omitted!',
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

function copyProject(token, server) {
	if (token && server && (token.length == 32)) {
		presentScreen('Copying project...<br>May take some time depending on size');
		$.post(getPageUrl('copyProject.php'), { 'redcap_csrf_token': getCSRFToken(), token: token, server: server }, function(html) {
			clearScreen();
			console.log(html);
			if (html.match(/error:/i) || html.match(/ERROR/)) {
				$.sweetModal({
					content: 'ERROR: '+html,
					icon: $.sweetModal.ICON_ERROR
				});
			} else {
				$.sweetModal({
					content: 'Successfully copied.',
					icon: $.sweetModal.ICON_SUCCESS
				});
			}
		});
	} else {
		$.sweetModal({
			content: 'Invalid Settings.',
			icon: $.sweetModal.ICON_ERROR
		});
	}
}

function enforceOneNumber(ob1, ob2, ob3) {
	if ($(ob1).val() !== '') {
		$(ob2).val('');
		$(ob3).val('');
	} else if ($(ob2).val() !== '') {
		$(ob1).val('');
		$(ob3).val('');
	} else if ($(ob3).val() !== '') {
		$(ob1).val('');
		$(ob2).val('');
	}
}

function copyToClipboard(element) {
    var $temp = $("<input>");
    $("body").append($temp);
    $temp.val($(element).text()).select();
    document.execCommand("copy");
    $temp.remove();
}

function summarizeRecordNow(url, recordId, csrfToken) {
	const postdata = {
		record: recordId,
		redcap_csrf_token: csrfToken,
	}
	if (!url.match(/summarizeRecordNow/)) {
		$.sweetModal({
			content: 'Invalid URL.',
			icon: $.sweetModal.ICON_ERROR
		});
		return;
	}
	const imageUrl = url.replace(/page=[^&]+/, "page="+encodeURIComponent("img/loading.gif"));
	presentScreen("Regenerating Summary Form for Record "+recordId, imageUrl);
	$.post(url, postdata, function(html) {
		console.log(html);
		clearScreen();
		if (html.match(/error/i)) {
			$.sweetModal({
				content: 'ERROR: '+html,
				icon: $.sweetModal.ICON_ERROR
			});
		} else {
			$.sweetModal({
				content: 'Record summary form has been regenerated.',
				icon: $.sweetModal.ICON_SUCCESS
			});
		}
	});
}


/**
 * Adapted from https://ramblings.mcpher.com/gassnippets2/converting-svg-to-png-with-javascript/
 * converts an svg string to base64 png using the domUrl and forces download
 * @param {string} svgText the svgtext
 * @param {number} [margin=0] the width of the border - the image size will be height+margin by width+margin
 * @param {string} [fill] optionally background canvas fill
 * @param {string} canvasFunction
 * @return {Promise} a promise to the base64 png image
 */
function svg2Image(svgText, margin,fill, canvasFunction) {
	// convert an svg text to png using the browser
	return new Promise(function(resolve, reject) {
		try {
			// can use the domUrl function from the browser
			var domUrl = window.URL || window.webkitURL || window;
			if (!domUrl) {
				throw new Error("(browser doesnt support this)")
			}

			// figure out the height and width from svg text
			var match = svgText.match(/height=\"(\d+)/m);
			var height = match && match[1] ? parseInt(match[1],10) : 200;
			var match = svgText.match(/width=\"(\d+)/m);
			var width = match && match[1] ? parseInt(match[1],10) : 200;
			margin = margin || 0;

			// it needs a namespace
			if (!svgText.match(/xmlns=\"/mi)){
				svgText = svgText.replace ('<svg ','<svg xmlns="http://www.w3.org/2000/svg" ') ;
			}

			// create a canvas element to pass through
			var canvas = document.createElement("canvas");
			canvas.width = width+margin*2;
			canvas.height = height+margin*2;
			var ctx = canvas.getContext("2d");


			// make a blob from the svg
			var svg = new Blob([svgText], {
				type: "image/svg+xml;charset=utf-8"
			});

			// create a dom object for that image
			var url = domUrl.createObjectURL(svg);

			// create a new image to hold it the converted type
			var img = new Image;

			// when the image is loaded we can get it as base64 url
			img.onload = function() {
				// draw it to the canvas
				ctx.drawImage(this, margin, margin);

				// if it needs some styling, we need a new canvas
				if (fill) {
					var styled = document.createElement("canvas");
					styled.width = canvas.width;
					styled.height = canvas.height;
					var styledCtx = styled.getContext("2d");
					styledCtx.save();
					styledCtx.fillStyle = fill;
					styledCtx.fillRect(0,0,canvas.width,canvas.height);
					styledCtx.strokeRect(0,0,canvas.width,canvas.height);
					styledCtx.restore();
					styledCtx.drawImage (canvas, 0,0);
					canvas = styled;
				}
				// we don't need the original any more
				domUrl.revokeObjectURL(url);
				// now we can resolve the promise, passing the base64 url
				const downloadUrl = canvasFunction(canvas);
				forceDownloadUrl(downloadUrl, 'chart.png');

				resolve(downloadUrl);
			};

			// load the image
			img.src = url;

		} catch (err) {
			reject('failed to convert svg to png ' + err);
		}
	});
}

function canvas2PNG(canvasOb) {
	return canvasOb.toDataURL("image/png");
}

function canvas2JPEG(canvasOb) {
	return canvasOb.toDataURL("image/jpeg");
}

function forceDownloadUrl(source, fileName){
	var el = document.createElement("a");
	el.setAttribute("href", source);
	el.setAttribute("download", fileName);
	document.body.appendChild(el);
	el.click();
	el.remove();
}

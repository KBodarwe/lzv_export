{**
 * plugins/importexport/native/templates/index.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * List of operations this plugin can perform
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	<script type="text/javascript">
		// Attach the JS file tab handler.
		$(function() {ldelim}
			$('#importExportTabs').pkpHandler('$.pkp.controllers.TabHandler');
			// $('#exportTabs').tabs('option', 'cache', true);
		{rdelim});
	</script>
	<div id="importExportTabs">
		<ul>
			<li><a href="#settings-tab">{translate key="plugins.importexport.lzv.settings"}</a></li>
			<li><a href="#exportSubmissions-tab">{translate key="plugins.importexport.lzv.export.articles"}</a></li>

			
		</ul>
		<div id="settings-tab">
			{capture assign=lzvSettingsGridUrl}{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="LZVExportPlugin" category="importexport" verb="settings" escape=false}{/capture}
			{load_url_in_div id="lzvSettingsGridContainer" url=$lzvSettingsGridUrl}
		</div>
		<div id="exportSubmissions-tab">
			<script type="text/javascript">
				$(function() {ldelim}
					// Attach the form handler.
					$('#exportXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
				{rdelim});
			</script>
			<form id="exportXmlForm" class="pkp_form" action="{plugin_url path="exportSubmissions"}" method="post">
				{csrf}
				{fbvFormArea id="submissionsXmlForm"}
					<submissions-list-panel
						v-bind="components.submissions"
						@set="set"
					>

						<template v-slot:item="{ldelim}item{rdelim}">
							<div class="listPanel__itemSummary">
								<label>
									<input
										type="checkbox"
										name="selectedSubmissions[]"
										:value="item.id"
										v-model="selectedSubmissions"
									/>
									<span 
										class="listPanel__itemSubTitle" 
										v-html="localize(
											item.publications.find(p => p.id == item.currentPublicationId).fullTitle,
											item.publications.find(p => p.id == item.currentPublicationId).locale
										)"
									>
									</span>
								</label>
								<pkp-button element="a" :href="item.urlWorkflow" style="margin-left: auto;">
									{{ __('common.view') }}
								</pkp-button>
							</div>
						</template>
					</submissions-list-panel>
					{fbvFormSection}
						<pkp-button :disabled="!components.submissions.itemsMax" @click="toggleSelectAll">
							<template v-if="components.submissions.itemsMax && selectedSubmissions.length >= components.submissions.itemsMax">
								{translate key="common.selectNone"}
							</template>
							<template v-else>
								{translate key="common.selectAll"}
							</template>
						</pkp-button>
						<pkp-button @click="submit('#exportXmlForm')">
							{translate key="plugins.importexport.lzv.exportSubmissions"}
						</pkp-button>
					{/fbvFormSection}
				{/fbvFormArea}
			</form>
		</div>
		
	</div>
{/block}

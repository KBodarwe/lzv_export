{**
 * plugins/importexport/lzv/templates/settingsForm.tpl
 *

 * LZV plugin settings
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#lzvSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form 
	class="pkp_form" 
	id="lzvSettingsForm" 
	method="post" 
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="$pluginName" category="importexport" verb="settings" save=true}"
>
	<!-- Always add the csrf token to secure your form -->
	{csrf}

	{fbvFormArea id="lzvSettingsFormArea"}
		{fbvFormSection label="plugins.importexport.lzv.test"}
			<p class="pkp_help">{translate key="plugins.importexport.lzv.test"}</p>
			{fbvElement 
				type="text" 
				id="testString" 
				value=$testString 
				description="plugins.importexport.lzv.test" 
				maxlength="50" 
				size=$fbvStyles.size.MEDIUM
			}
			<span class="instruct">{translate key="plugins.importexport.lzv.test"}</span><br/>
		{/fbvFormSection}
		{fbvFormSection list="true" label="plugins.importexport.lzv.test"}
			{fbvElement 
				type="checkbox" 
				id="testBoolOne" 
				description="plugins.importexport.lzv.test" 
				checked=$testBoolOne|compare:true
			}
		{/fbvFormSection}
		{fbvFormSection list="true" label="plugins.importexport.lzv.test"}
			{fbvElement 
				type="checkbox" 
				id="testBoolTwo" 
				description="plugins.importexport.lzv.test" 
				checked=$testBoolTwo|compare:true}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>

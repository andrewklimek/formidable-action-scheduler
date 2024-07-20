<p>
	<label for="frm_action_scheduler_async">
		<input type="checkbox" value="1" id="frm_action_scheduler_async" name="frm_action_scheduler_async" <?php checked($defer); ?>>
		Do all actions asynchronously <span class="frm_help frm_icon_font frm_tooltip_icon" title="" data-original-title="If form submits slow because of the actions, this should make it sumbit much faster."></span>
	</label>
<h4>Global Debug:</h4>
<p style="display:flex;gap:2em">
	<label><input type=radio value="respect" name="frm_action_scheduler_debug" <?php checked($debug, 'respect'); ?>>Respect Action Setting</label>
	<label><input type=radio value="1" name="frm_action_scheduler_debug" <?php checked($debug, 1); ?>>Global On</label>
	<label><input type=radio value="0" name="frm_action_scheduler_debug" <?php checked($debug, 0); ?>>Global Off</label>

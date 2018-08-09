<script data-relocate="true">
	require(['Language'], function(Language) {
		Language.addObject({
			'wcf.acp.pip.menu.boxVisibilityExceptions.hiddenEverywhere': '{lang}wcf.acp.pip.menu.boxVisibilityExceptions.hiddenEverywhere{/lang}',
			'wcf.acp.pip.menu.boxVisibilityExceptions.visibleEverywhere': '{lang}wcf.acp.pip.menu.boxVisibilityExceptions.visibleEverywhere{/lang}'
		});
		
		var boxVisibleEverywhere = elById('boxVisibleEverywhere');
		var boxVisibilityExceptionsLabel = elBySel('#boxVisibilityExceptionsContainer > dt > label');
		
		function updateVisibilityExceptions() {
			if (boxVisibleEverywhere.checked) {
				boxVisibilityExceptionsLabel.innerHTML = Language.get('wcf.acp.pip.menu.boxVisibilityExceptions.visibleEverywhere');
			}
			else {
				boxVisibilityExceptionsLabel.innerHTML = Language.get('wcf.acp.pip.menu.boxVisibilityExceptions.hiddenEverywhere');
			}
		}
		
		boxVisibleEverywhere.addEventListener('change', updateVisibilityExceptions);
		elById('boxVisibleEverywhere_no').addEventListener('change', updateVisibilityExceptions);
		
		updateVisibilityExceptions();
	});
</script>

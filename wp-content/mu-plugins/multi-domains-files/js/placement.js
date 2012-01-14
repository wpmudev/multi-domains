(function ($) {
$(function () {
	
var $name = $("#blogname");
var $domain = $("#domain"); 
var $suffix = false;

function update_text () {
	if (!$suffix || !$suffix.length) return;
	var $strong = $suffix.find('~p').has("strong").find("strong");
	if (!$strong) return;
	
	$strong.text(
		l10nMd.your_address + ' ' + $name.val() + '.' + $domain.val()
	);
}

// Init
if ($name.length && $domain.length) {
	$suffix = $name.next(".suffix_address");
	if (!$suffix.length) return false;
	 
	$suffix.html('.').append($domain); // Move domain selection
	update_text(); // Update text
	
	// Bind handlers
	$name.change(update_text);
	$domain.change(update_text);
}
	
	
});
})(jQuery);
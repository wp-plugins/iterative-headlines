<?php
	function iterative_advice_javascript($advice) {
		return '<script type="text/javascript">
			var advices = ' . json_encode($advice) . ';
			var iterativeStartAdvices = function() {
				if(advices && advices.length) { 
					jQuery(".headline-tip .text").html(advices[0]).attr("x-id", 0);
					jQuery(".headline-tip").fadeIn();
				}
			}
			jQuery(function() {
				jQuery(".iterative-headline-variant").parent().on("change", ".iterative-headline-variant", (function() {
					var titles = [];
					jQuery(".iterative-headline-variant").each(function() {
						if(jQuery(this).val() != "")
							titles.push(jQuery(this).val());
					});
					// get new headlines
					// get the stuff.
					jQuery.ajax("' . IterativeAPI::getURL("advice") . '", {"data": {"variants": JSON.stringify(titles), "unique_id": "' . IterativeAPI::getGUID() . '"}}).done(function(success) {
						console.log(success);
						jQuery(".headline-tip").fadeOut(function() {
							jQuery(".headline-tip .text").attr("x-id", 0);
							advices = success["messages"]
							iterativeStartAdvices();
						});
					});
				}));
				jQuery(".headline-tip .dismiss").click(function() {
					jQuery(".headline-tip").fadeOut(function() {
						var id = jQuery(".headline-tip .text").attr("x-id");
						id++;
						if(advices[id] != undefined) {
							jQuery(".headline-tip .text").html(advices[id]);
							jQuery(".headline-tip").fadeIn();
							jQuery(".headline-tip .text").attr("x-id", id);
						}
					});
				});
				iterativeStartAdvices();
			});
		</script>';
	}
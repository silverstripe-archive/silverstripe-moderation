
// Monkey patch onresize code to force overflow:auto on ModelAdminPanel
// @todo: Remove once layout gets re-written to be good
var old_resize = window.onresize;
window.onresize = function(){
	old_resize();
	$('ModelAdminPanel').style.overflow = 'auto'; $('ModelAdminPanel').style.overflowX = 'hidden';
	$('ModelAdminPanel').style.height = '100%';
}

// Add some new animations to jQuery
jQuery.fn.niceSlideIn = function(callback) {
	var h = this.innerHeight();
	this.css({marginBottom:0, height:0, opacity:0});
	this.animate({height: h, marginBottom: '1em', opacity: 1}, 200, function(){jQuery(this).css({height: 'auto'}); callback && callback.call(this);});
}

jQuery.fn.niceSlideOut = function(callback) {
	this.animate({height: 0, marginBottom: 0, paddingTop: 0, paddingBottom: 0, opacity: 0}, 200, callback);
}

jQuery.fn.niceSlideOutAndRemove = function(callback) {
	this.niceSlideOut(function(){jQuery(this).remove(); callback && callback.call(this);});
}

jQuery.fn.fadeOutAndRemove = function(callback) {
	this.fadeOut(200, function(){jQuery(this).remove(); callback && callback.call(this);});
}

Behaviour.register({
	'#moderation' : {
		domChanged: function() {
			jQuery('#form_actions_right').remove();
			Behaviour.apply();
			if (window.onresize) window.onresize();
		},
		
		elementMoved: function(id){
			jQuery('#moderation-block-'+id).niceSlideOutAndRemove();
			this.refresh();
		},
		
		currentPage: function(){
			return parseInt(jQuery('#Form_CurrentSearchForm input[name=Page]').val());
		},
		
		prevPage: function(){
			if (this.currentPage() > 0) this.loadPage(-1);
		},
		
		nextPage: function(){
			this.loadPage(1);
		},

		postForm: function(callback) {
			var $ = jQuery, self = this, form = $('#Form_CurrentSearchForm');
			$.post(form.attr('action'), form.formToArray(), function(result){
				var result = $(result);
				var moderation = result.filter(function(){return this.id == 'moderation';});
				var pagination = result.filter(function(){return this.id == 'pagination';});
				
				callback.call(self, moderation, pagination);
			});
		},

		loadPage: function(dir){
			var $ = jQuery, self = this;
			$('#pagination').fadeOutAndRemove();
		
			// Adjust page and resend search form
			$('#Form_CurrentSearchForm input[name=Page]').val(this.currentPage()+dir);
			this.postForm(function(_in, pag){
				// Get amount to slide in / out 
				var slide_delta = dir * $('#ModelAdminPanel').innerWidth();
				
				// Remember current width of moderation div, then force incoming and outgoing to be that width
				var out = $('#moderation'); var width = out.width();
				out.css({width: width});
				_in.css({width: width, marginLeft: slide_delta });

				// Change ID of outgoing div, add incoming div 				
				out.attr('id', 'moderation-oscroll');
				$('#ModelAdminPanel').append(_in);
				
				// Animate
				out.animate({left: -slide_delta}, 500, function(){ $(this).remove(); });
				_in.animate({marginLeft: 0}, 500, function(){ 
					$(this).css({width:'auto'}); 
					$('#ModelAdminPanel').prepend(pag); 
					self.domChanged(); 
				});
			});
		},
				
		refresh: function(){
			var $ = jQuery, self = this;
			
			// Resend search form
			this.postForm(function(mod, pag){
				var mods = mod.find('.moderation-block');
				
				// If no more results for this page, show previous
				if (mods.length == 0) self.prevPage();
				
				// Otherwise, step through this pages current moderation-blocks, adding new ones to end
				else {
					mods.each(function(){
						if ($('#'+$(this).attr('id')).length == 0) $(this).appendTo('#moderation').niceSlideIn();
					})
					$('div#pagination').replaceWith(pag);
					
					self.domChanged();
				}
			});
		}
	}
});

Behaviour.register({
	'#moderation input.ajaxaction': {
		onclick: function(e){
			new Ajax.Request( this.getAttribute('action',2)+'&ajax=1', {
				asynchronous : true,
				onSuccess: Ajax.Evaluator,
				onFailure : function(response) { statusMessage('Failure'); }
			});
			
			Event.stop(e);
			return false;
		}
	}
});

Behaviour.register({
	'#pagination input.pageaction': {
		onclick: function(e){
			var dir = this.getAttribute('action', 2);
			dir == 'prev' ? $('moderation').prevPage() : $('moderation').nextPage();
			
			Event.stop(e);
			return false;
		}
	}
});

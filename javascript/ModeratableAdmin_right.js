Behaviour.register({
	'#Form_EditForm' : {
		elementMoved: function(id) {
			var el = $('moderation-block-'+id);
			new Effect.Opacity(el, {
				duration: 0.5,
				transition: Effect.Transitions.linear,
				from: 1.0, to: 0.0,
				afterFinish: function(){
					el.parentNode.removeChild(el);
				}
			});
		}
	}
});

Behaviour.register({
	'#Form_EditForm input.ajaxaction': {
		onclick: function(e){
			new Ajax.Request( this.getAttribute('action',2)+'&ajax=1', {
				asynchronous : true,
				onSuccess: Ajax.Evaluator,
				onFailure : function(response) { 
					statusMessage('Failure');
				}
			});
			
			Event.stop(e);
			return false;
		}
	}
})

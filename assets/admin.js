jQuery(document).ready(function($){
    $('.ve-lupe').on('click', function(){
        var term = $(this).data('term'),
            id   = term.replace(/[^a-z0-9]/gi, ''),
            ct   = $('#result-' + id);
        ct.html('<p>⏳ Lädt…</p>');
        $.post(ve_ajax.ajax_url, { action: 've_run_search', term: term }, function(res){
            ct.html(res);
        });
    });
});
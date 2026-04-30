jQuery(function ($) {
  const button = $('#seo-autoptimiser-analyse');
  const resultBox = $('#seo-autoptimiser-result');

  if (!button.length) return;

  button.on('click', function () {
    const postId = button.data('post-id');
    button.prop('disabled', true).text('Optimizing...');
    resultBox.text('Running AI SEO optimization...');

    $.post(seoAutoptimiser.ajaxUrl, {
      action: 'seo_autoptimiser_analyse',
      nonce: seoAutoptimiser.nonce,
      postId,
    })
      .done(function (response) {
        if (!response.success) {
          resultBox.text(response.data?.message || 'Optimization failed.');
          return;
        }

        const data = response.data;
        const keywords = (data.keywords || []).join(', ');
        resultBox.html(
          '<strong>Done.</strong><br>' +
            'Title: ' + (data.optimized_title || '') + '<br>' +
            'Keywords: ' + keywords + '<br>' +
            'Meta description: ' + (data.meta_description || '') + '<br>' +
            (data.applied
              ? 'Optimized content was auto-applied.'
              : 'Preview generated. Enable auto-apply in settings to save automatically.')
        );
      })
      .fail(function () {
        resultBox.text('Network/API error. Check logs and API key.');
      })
      .always(function () {
        button.prop('disabled', false).text('Run SEO Optimization');
      });
  });
});

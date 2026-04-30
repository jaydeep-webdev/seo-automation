jQuery(function ($) {
  function renderSuggestions(data) {
    const changes = data.text_changes || [];
    const items = changes
      .map(
        (c, i) =>
          `<div style="border-left:3px solid #2271b1;padding:8px;margin:8px 0;background:#fff">
            <strong>${i + 1}. Change suggested</strong><br>
            <em>Current:</em> ${$('<div>').text(c.original_text || '').html()}<br>
            <em>Suggested:</em> ${$('<div>').text(c.suggested_text || '').html()}<br>
            <small>Reason: ${$('<div>').text(c.reason || '').html()}</small>
          </div>`
      )
      .join('');
    return `<div><p><strong>SEO Suggestions</strong></p>${items || 'No specific text highlights returned.'}</div>`;
  }

  function run(postId, resultBox) {
    resultBox.html('Running AI optimization...');
    $.post(seoAutoptimiser.ajaxUrl, { action: 'seo_autoptimiser_analyse', nonce: seoAutoptimiser.nonce, postId })
      .done(function (response) {
        if (!response.success) {
          resultBox.text(response.data?.message || 'Optimization failed.');
          return;
        }
        const data = response.data;
        resultBox.html(
          `<div><strong>Optimized Title:</strong> ${$('<div>').text(data.optimized_title || '').html()}</div>
           <div><strong>Keywords:</strong> ${$('<div>').text((data.keywords || []).join(', ')).html()}</div>
           <div><strong>Meta Description:</strong> ${$('<div>').text(data.meta_description || '').html()}</div>
           ${renderSuggestions(data)}
           <div style="margin-top:8px;color:#666">${data.applied ? 'Auto-applied to page.' : 'Suggestion mode: copy/replace manually in Elementor editor.'}</div>`
        );
      })
      .fail(function () {
        resultBox.text('Network/API error.');
      });
  }

  const button = $('#seo-autoptimiser-analyse');
  const resultBox = $('#seo-autoptimiser-result');

  if (button.length) {
    button.on('click', function () {
      run(button.data('post-id'), resultBox);
    });
  }

  if (window.seoAutoptimiser && seoAutoptimiser.isElementor && seoAutoptimiser.postId) {
    const floating = $('<button type="button" style="position:fixed;top:70px;right:20px;z-index:99999;background:#2271b1;color:#fff;border:none;padding:10px 14px;border-radius:4px;cursor:pointer;">SEO Optimize</button>');
    const panel = $('<div style="display:none;position:fixed;top:110px;right:20px;z-index:99999;width:420px;max-height:70vh;overflow:auto;background:#f0f0f1;border:1px solid #ccc;padding:12px;"><div style="display:flex;justify-content:space-between"><strong>SEO AUTOPTIMISER Suggestions</strong><button type="button" id="seo-close">×</button></div><div id="seo-panel-body" style="margin-top:8px;font-size:12px"></div></div>');
    $('body').append(floating).append(panel);
    floating.on('click', function () {
      panel.show();
      run(seoAutoptimiser.postId, panel.find('#seo-panel-body'));
    });
    panel.on('click', '#seo-close', function () {
      panel.hide();
    });
  }
});

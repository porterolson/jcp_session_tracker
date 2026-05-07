(function () {
	if (!window.jcpstRecentJobs) {
		return;
	}

	var config = window.jcpstRecentJobs;
	var roots = document.querySelectorAll('.jcpst-recent-jobs[data-interactive="1"]');

	function renderStats(container, stats) {
		if (!container || !stats) {
			return;
		}

		var applicantLabel = stats.applicant_count === 1 ? 'applicant' : 'applicants';
		container.innerHTML =
			'<div class="jcpst-job-stats">' +
				'<div class="jcpst-job-stats__count">' +
					'<span class="jcpst-job-stats__count-number">' + stats.applicant_count + '</span>' +
					'<span class="jcpst-job-stats__count-label">' + applicantLabel + '</span>' +
				'</div>' +
				'<div class="jcpst-job-stats__chart">' +
					'<div class="jcpst-job-stats__chart-head">' +
						'<span>Interview</span>' +
						'<span>Offer</span>' +
					'</div>' +
					'<div class="jcpst-job-stats__plot">' +
						'<div class="jcpst-job-stats__bar-wrap"><span class="jcpst-job-stats__bar jcpst-job-stats__bar--interview" style="height:' + stats.interview_rate + '%"></span></div>' +
						'<div class="jcpst-job-stats__bar-wrap"><span class="jcpst-job-stats__bar jcpst-job-stats__bar--offer" style="height:' + stats.offer_rate + '%"></span></div>' +
					'</div>' +
					'<div class="jcpst-job-stats__chart-values">' +
						'<span>' + stats.interview_rate + '%</span>' +
						'<span>' + stats.offer_rate + '%</span>' +
					'</div>' +
				'</div>' +
				'<button type="button" class="jcpst-job-stats__edit">Update answers</button>' +
				'<div class="jcpst-job-stats__note">Aggregated from Job Connections Project responses.</div>' +
			'</div>';
	}

	function setSelected(group, value) {
		var options = group.querySelectorAll('[data-value]');
		options.forEach(function (option) {
			option.classList.toggle('is-selected', option.getAttribute('data-value') === value);
		});
		group.setAttribute('data-selected', value);
	}

	function getSelected(group) {
		return group ? (group.getAttribute('data-selected') || '') : '';
	}

	function bindToggleGroup(group) {
		group.addEventListener('click', function (event) {
			var option = event.target.closest('[data-value]');
			if (!option) {
				return;
			}

			setSelected(group, option.getAttribute('data-value'));

			if (group.getAttribute('data-question') === 'applied' && option.getAttribute('data-value') === '0') {
				var card = group.closest('.jcpst-recent-jobs__item');
				if (!card) {
					return;
				}

				['interviewed', 'offered'].forEach(function (question) {
					var related = card.querySelector('.jcpst-job-response__group[data-question="' + question + '"]');
					if (related) {
						setSelected(related, '0');
					}
				});
			}
		});
	}

	function bindCard(card) {
		var form = card.querySelector('.jcpst-job-response');
		var stats = card.querySelector('.jcpst-job-response__stats');
		var status = card.querySelector('.jcpst-job-response__status');
		var submit = card.querySelector('.jcpst-job-response__submit');

		if (!form || !stats) {
			return;
		}

		form.querySelectorAll('.jcpst-job-response__group').forEach(bindToggleGroup);
		form.querySelectorAll('.jcpst-job-response__group').forEach(function (group) {
			group.addEventListener('click', function () {
				if (status) {
					status.textContent = '';
				}
				if (submit) {
					submit.disabled = false;
				}
			});
		});

		stats.addEventListener('click', function (event) {
			if (!event.target.classList.contains('jcpst-job-stats__edit')) {
				return;
			}

			stats.hidden = true;
			form.hidden = false;
			if (status) {
				status.textContent = '';
			}
		});

		if (!submit) {
			return;
		}

		submit.addEventListener('click', function () {
			var appliedGroup = form.querySelector('[data-question="applied"]');
			var interviewedGroup = form.querySelector('[data-question="interviewed"]');
			var offeredGroup = form.querySelector('[data-question="offered"]');

			var applied = getSelected(appliedGroup);
			var interviewed = getSelected(interviewedGroup);
			var offered = getSelected(offeredGroup);

			if (applied === '') {
				submit.disabled = false;
				if (status) {
					status.textContent = 'Choose whether you applied before submitting.';
				}
				return;
			}

			if (applied === '0') {
				interviewed = '0';
				offered = '0';
				setSelected(interviewedGroup, '0');
				setSelected(offeredGroup, '0');
			}

			if (interviewed === '' || offered === '') {
				submit.disabled = false;
				if (status) {
					status.textContent = 'Answer all three questions before submitting.';
				}
				return;
			}

			submit.disabled = true;
			if (status) {
				status.textContent = '';
			}

			var payload = new URLSearchParams();
			payload.append('action', 'jcpst_save_job_response');
			payload.append('_ajax_nonce', config.nonce || '');
			payload.append('job_path', card.getAttribute('data-job-path') || '');
			payload.append('job_url', card.getAttribute('data-job-url') || '');
			payload.append('job_title', card.getAttribute('data-job-title') || '');
			payload.append('applied', applied);
			payload.append('interviewed', interviewed);
			payload.append('offered', offered);

			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: payload.toString()
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (data) {
					if (!data || !data.success || !data.data || !data.data.stats) {
						throw new Error('Invalid response');
					}

					renderStats(stats, data.data.stats);
					stats.hidden = false;
					form.hidden = true;

					if (status) {
						status.textContent = '';
					}
				})
				.catch(function () {
					if (status) {
						status.textContent = 'We could not save your response right now.';
					}
				})
				.finally(function () {
					submit.disabled = false;
				});
		});
	}

	roots.forEach(function (root) {
		root.querySelectorAll('.jcpst-recent-jobs__item').forEach(bindCard);
	});
})();

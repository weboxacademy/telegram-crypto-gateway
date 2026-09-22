jQuery(function ($) {

	$(document).on('click', '#wtg-test-connection', function (e) {

		e.preventDefault();

		const button = $(this);
		const result = $('#wtg-test-result');

		button.prop('disabled', true).text('Testing...');

		result
			.removeClass('success error')
			.text('');

		$.ajax({
			url: WTGAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wtg_test_connection',
				nonce: WTGAdmin.nonce
			}
		})
		.done(function (response) {

			if (response.success) {

				result
					.addClass('success')
					.text(response.data.message);

			} else {

				result
					.addClass('error')
					.text(
						response.data &&
						response.data.message
							? response.data.message
							: 'Connection failed.'
					);
			}
		})
		.fail(function () {

			result
				.addClass('error')
				.text('Unable to contact the server.');

		})
		.always(function () {

			button
				.prop('disabled', false)
				.text('Test API Connection');

		});
	});

});
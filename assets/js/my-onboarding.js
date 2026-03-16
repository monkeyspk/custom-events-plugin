jQuery(document).ready(function($) {
    $(window).on('load', function() {
        $.post(MyOnboardingAjax.ajaxUrl, {
            action: 'load_onboarding_content',
            order_id: MyOnboardingAjax.orderId
        }, function(response) {
            if(response.success) {
                $('#myOnboardingContent').html(response.data);
                // Zeige das Overlay an, indem wir die Klasse 'open' hinzufügen.
                $('#myOnboardingOverlay').addClass('open');

                var currentStep = 1;
                var totalSteps = $('.my-onboarding-step').length;

                $('.my-onboarding-next').on('click', function() {
                    if (currentStep < totalSteps) {
                        showStep(currentStep + 1);
                    } else {
                        $('#myOnboardingOverlay').removeClass('open');
                    }
                });

                $('.my-onboarding-prev').on('click', function() {
                    if (currentStep > 1) {
                        showStep(currentStep - 1);
                    }
                });

                function showStep(step) {
                    $('.my-onboarding-step').hide();
                    $('.my-onboarding-step[data-step="'+step+'"]').show();
                    currentStep = step;
                    if (step > 1) {
                        $('.my-onboarding-prev').show();
                    } else {
                        $('.my-onboarding-prev').hide();
                    }
                    if (step === totalSteps) {
                        $('.my-onboarding-next').text('Fertig');
                    } else {
                        $('.my-onboarding-next').text('Weiter');
                    }
                }

                $('#myOnboardingClose').on('click', function() {
                    $('#myOnboardingOverlay').removeClass('open');
                });
            }
        });
    });
});

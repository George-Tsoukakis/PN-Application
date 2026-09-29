/* -----------------------------------------------------------------------
 * Field validation (9.9.41)
 * The form no longer carries `novalidate`, so the browser blocks submission
 * on empty required fields. This adds Greek messages, marks the offending
 * field, and re-marks whatever the server rejected after a redirect.
 * --------------------------------------------------------------------- */
jQuery(document).ready(function ($) {

    var $form = $('#jbli_submit_form');
    if (!$form.length) { return; }

    var JBLI_MSG = {
        empty:   'Παρακαλώ συμπληρώστε αυτό το πεδίο.',
        select:  'Παρακαλώ επιλέξτε μια τιμή.',
        email:   'Παρακαλώ συμπληρώστε ένα έγκυρο email.',
        tel:     'Παρακαλώ συμπληρώστε έγκυρο τηλέφωνο (τουλάχιστον 10 ψηφία).',
        consent: 'Παρακαλώ αποδεχτείτε τους όρους για να συνεχίσετε.',
        toolong: 'Το κείμενο είναι μεγαλύτερο από το επιτρεπτό όριο.'
    };

    function jbliMessageFor(el) {
        var v = el.validity;
        if (v.tooLong) { return JBLI_MSG.toolong; }
        if (el.type === 'checkbox') { return JBLI_MSG.consent; }
        if (el.tagName === 'SELECT') { return JBLI_MSG.select; }
        if (v.valueMissing) { return JBLI_MSG.empty; }
        if (el.type === 'email') { return JBLI_MSG.email; }
        if (el.type === 'tel') { return JBLI_MSG.tel; }
        return JBLI_MSG.empty;
    }

    function jbliWrapper($el) {
        var $w = $el.closest('.jbli_field, .jbli_form_field, .jbli_consent_box');
        return $w.length ? $w : $el.parent();
    }

    function jbliMark($el, message) {
        var $w = jbliWrapper($el);
        $w.addClass('jbli_field_invalid');
        $el.attr('aria-invalid', 'true');
        $w.find('.jbli_field_error').remove();
        $w.append($('<span class="jbli_field_error" role="alert"></span>').text(message));
    }

    function jbliClear($el) {
        var $w = jbliWrapper($el);
        $w.removeClass('jbli_field_invalid');
        $el.removeAttr('aria-invalid');
        $w.find('.jbli_field_error').remove();
    }

    /* Native validation: our own message, our own highlight. */
    $form.on('invalid', ':input', function (e) {
        e.preventDefault();
        jbliMark($(this), jbliMessageFor(this));
    });

    /* Clear the marking as soon as the field becomes valid again. */
    $form.on('input change blur', ':input', function () {
        if (this.checkValidity && this.checkValidity()) { jbliClear($(this)); }
    });

    /* Phone needs at least 10 digits — the server enforces it, so should we. */
    var $phone = $('#job_contact_phone');
    if ($phone.length) {
        var jbliPhoneCheck = function () {
            var digits = ($phone.val() || '').replace(/\D/g, '');
            $phone[0].setCustomValidity(
                digits.length && digits.length < 10 ? JBLI_MSG.tel : ''
            );
        };
        $phone.on('input change', jbliPhoneCheck);
        jbliPhoneCheck();
    }

    $form.on('submit', function () {
        var invalid = this.querySelectorAll(':invalid');
        if (!invalid.length) { return true; }

        var $first = $(invalid[0]);
        jbliMark($first, jbliMessageFor(invalid[0]));

        if ($first[0].scrollIntoView) {
            $first[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        setTimeout(function () { $first.trigger('focus'); }, 250);

        return true;
    });

    /* Fields the server rejected, carried across the redirect. */
    var serverErrors = ($form.attr('data-jbli-errors') || '').split(',');
    var $firstServer = null;

    $.each(serverErrors, function (i, name) {
        name = $.trim(name);
        if (!name) { return; }

        var $el = $form.find('[name="' + name + '"]').first();
        if (!$el.length) { return; }

        jbliMark($el, JBLI_MSG.empty);
        if (!$firstServer) { $firstServer = $el; }
    });

    if ($firstServer && $firstServer[0].scrollIntoView) {
        $firstServer[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});


jQuery(document).ready(function($) {
    var $addressInput = $('#job_address');
    if (!$addressInput.length) {
        return;
    }

    if (typeof google !== 'undefined' && google.maps && google.maps.places) {
        var jbli_autocomplete = new google.maps.places.Autocomplete($addressInput[0], {
            types: ['address']
        });

        jbli_autocomplete.addListener('place_changed', function() {
            var jbli_place = jbli_autocomplete.getPlace();
            if (!jbli_place.geometry) {
                return;
            }

            var jbli_lat = jbli_place.geometry.location.lat();
            var jbli_lng = jbli_place.geometry.location.lng();

            $('#job_lat').val(jbli_lat);
            $('#job_lng').val(jbli_lng);
        });

        $addressInput.on('keydown', function(e) {
            if (e.key === 'Enter') {
                var $pacContainer = $('.pac-container');
                if ($pacContainer.length && $pacContainer.is(':visible')) {
                    e.preventDefault();
                }
            }
        });
    }

    var $geoBtn = $('#jbli_get_location_btn');
    if ($geoBtn.length) {
        $geoBtn.on('click', function(e) {
            e.preventDefault();

            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }

            $geoBtn.addClass('jbli_loading');

            navigator.geolocation.getCurrentPosition(
                function(jbli_position) {
                    var jbli_lat = jbli_position.coords.latitude;
                    var jbli_lng = jbli_position.coords.longitude;

                    $('#job_lat').val(jbli_lat);
                    $('#job_lng').val(jbli_lng);

                    if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
                        var jbli_geocoder = new google.maps.Geocoder();
                        var jbli_latlng   = { lat: parseFloat(jbli_lat), lng: parseFloat(jbli_lng) };

                        jbli_geocoder.geocode({ location: jbli_latlng }, function(jbli_results, jbli_status) {
                            $geoBtn.removeClass('jbli_loading');
                            if (jbli_status === 'OK') {
                                if (jbli_results[0]) {
                                    $addressInput.val(jbli_results[0].formatted_address);
                                }
                            }
                        });
                    } else {
                        $geoBtn.removeClass('jbli_loading');
                    }
                },
                function(error) {
                    $geoBtn.removeClass('jbli_loading');
                    var jbli_msg = 'Error getting location.';
                    if (error.code === error.PERMISSION_DENIED) {
                        jbli_msg = 'Permission denied. Please allow location access.';
                    }
                    alert(jbli_msg);
                }, {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                }
            );
        });
    }
});

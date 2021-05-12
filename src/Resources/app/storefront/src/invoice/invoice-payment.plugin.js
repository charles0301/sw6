import Plugin from 'src/plugin-system/plugin.class';

export default class NovalnetInvoicePayment extends Plugin {

    init() {

		const paymentNameHtmlContent = document.getElementById('novalnetinvoice-payment-name');
		const paymentName = paymentNameHtmlContent.value;
		const selectedPaymentId = document.querySelector(this.getSelectors(paymentName).selectedPaymentId);
		const submitButton = document.querySelector(this.getSelectors(paymentName).submitButton);
        const invoiceId = document.querySelector(this.getSelectors(paymentName).invoiceId);
        const paymentRadioButton = document.querySelectorAll(this.getSelectors(paymentName).paymentRadioButton);
        const config	= JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-' + paymentName + '-payment-config'));

		this._createScript(() => {
            document.getElementById('novalnet-payment');
            if(paymentName == 'novalnetinvoiceguarantee' && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company))) {
                const element = document.getElementById(paymentName + 'DobField');
                element.style.display = "block";
            }
        });

        if( selectedPaymentId !== undefined && selectedPaymentId !== null && invoiceId !== undefined && selectedPaymentId.value === invoiceId.value )
        {
			document.getElementById(paymentName + "-payment").style.display = "block";
		}

        // Submit handler
        submitButton.addEventListener('click', (event) => {
			const selectedPaymentId = document.querySelector(this.getSelectors(paymentName).selectedPaymentId);
			const dob		= document.getElementById(paymentName + 'Dob');

			if( invoiceId.value !== undefined && invoiceId.value !== '' && selectedPaymentId.value === invoiceId.value )
            {
                if ( paymentName === 'novalnetinvoiceguarantee' && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company)) ) {
                    if ( dob === undefined || dob.value === '' )
                    {
                        if( document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
                        {
                            selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
                            document.getElementById('doForceInvoicePayment').value = 1;
                        }
                        else
                        {
                            this.preventForm(dob, paymentName, config.text.dobEmpty);
                        }

                    } else if ( dob !== undefined && dob.value !== '' )
                    {
                        const age = this.validateAge(dob.value);

                        if ( age < 18 && config.forceGuarantee != undefined && config.forceGuarantee != 1 )
                        {
                            this.preventForm(dob, paymentName, config.text.dobInvalid);
                        }
                        else if( age < 18 && document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
                        {
                            selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
                            document.getElementById('doForceInvoicePayment').value = 1;
                        }
                    }
                }
			}
		});

		// Show/hide the payment form based on the payment selected
        paymentRadioButton.forEach((element) => {
            element.addEventListener('click', () => {
                this.showPaymentForm(element, paymentName);
            });
        });
	}

	_createScript(callback) {
        const url = 'https://cdn.novalnet.de/js/v2/NovalnetUtility.js';
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false);
        document.head.appendChild(script);
    }

    getSelectors(paymentName) {
        return {
            invoiceId: '#' + paymentName + 'Id',
            selectedPaymentId: '#confirmPaymentForm input[name=paymentMethodId]:checked',
            submitButton: '#confirmPaymentForm button[type="submit"]',
            paymentRadioButton: '#confirmPaymentForm input[name="paymentMethodId"]'
        };
    }

    validateAge(DOB) {
		var today = new Date();

        if(DOB === undefined || DOB === '')
		{
			return NaN;
		}

        var birthDate = DOB.split(".");
		var age = today.getFullYear() - birthDate[2];
		var m = today.getMonth() - birthDate[1];
		m = m + 1
		if (m < 0 || (m == '0' && today.getDate() < birthDate[0])) {
			age--;
		}
		return age;
	}

	showPaymentForm( el, paymentName ) {

		const invoiceId = document.querySelector(this.getSelectors(paymentName).invoiceId);

		if( invoiceId !== undefined && invoiceId.value !== '' && el.value === invoiceId.value )
        {
			document.getElementById(paymentName + "-payment").style.display = "block";
		} else {
			document.getElementById(paymentName + "-payment").style.display = "none";
		}
    }

	preventForm(field, paymentName, errorMessage)
	{
		field.style.borderColor = "red";
		event.preventDefault();
		event.stopImmediatePropagation();
		var element = document.getElementById(paymentName + '-error-container');

		var elementContent = element.querySelector(".alert-content");
		elementContent.innerHTML = '';
		if ( errorMessage !== undefined && errorMessage !== '' ) {

			elementContent.innerHTML = errorMessage;
			element.style.display = "block";
			elementContent.focus();
		} else {
			element.style.display = "none";
		}
		return false;
	}
}

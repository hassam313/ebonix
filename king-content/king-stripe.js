/*
    File: king-content/king-stripe.js
    Description: Stripe payment handling for membership and coin top-ups
*/

var stripeKey      = document.querySelector('#payment-box').dataset.consumerKey;
var createOrderUrl = document.querySelector('#payment-box').dataset.createOrderUrl;
var returnUrl      = document.querySelector('#payment-box').dataset.returnUrl;
var stripe         = Stripe(stripeKey);

let elements;
let payment_intent_id;

const paymentFrm        = document.querySelector("#payment-form");
const clientSecretParam = new URLSearchParams(window.location.search).get("payment_intent_client_secret");

// Hide spinner on first load (only show when needed)
setProcessing(false);

// ── If returning from Stripe redirect, check payment status ──────────────────
if (clientSecretParam) {
    checkStatus();
}

// Attach submit handler
paymentFrm.addEventListener("submit", handleSubmit);

// ── Called when user picks a membership plan (radio button) ──────────────────
function memClick(myRadio) {
    var st = document.getElementById('mem_plan');
    var pp = document.getElementById('memp_plan');
    if (st) st.value = myRadio.value;
    if (pp) pp.value = myRadio.value;

    let button = document.getElementById("memnext");
    if (button) button.disabled = false;

    if (!clientSecretParam) {
        initialize();
    }
}

// ── Called when user clicks Next on coin top-up ──────────────────────────────
function cmemnext() {
    var ast = document.getElementById('mem_plan');
    var app = document.getElementById('memp_plan');
    var cb  = document.getElementById('credit-box');

    var price = cb ? cb.value : '';

    if (ast) ast.value = price;
    if (app) app.value = price;

    var element = document.getElementById("membership");
    if (element) element.classList.toggle("step-2");

    if (!clientSecretParam) {
        initialize();
    }
}

// ── Called when user clicks Buy Now on a fixed coin pack ─────────────────────
function buyCoins(price) {
    var ast = document.getElementById('mem_plan');
    var app = document.getElementById('memp_plan');

    if (ast) ast.value = price;
    if (app) app.value = price;

    var element = document.getElementById("membership");
    if (element) {
        element.classList.add("step-2");
    }

    if (!clientSecretParam) {
        initialize();
    }
}

// ── Initialize Stripe Elements with a new PaymentIntent ─────────────────────
async function initialize() {
    let usd = document.getElementById("mem_plan") ? document.getElementById("mem_plan").value : '';

    if (!usd || usd == '0') {
        showMessage("Please select a plan or enter an amount.");
        return;
    }

    setProcessing(true);

    try {
        const response = await fetch(createOrderUrl, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ request_type: 'create_payment_intent', price: usd }),
        });

        const data = await response.json();

        if (data.error) {
            showMessage("Payment error: " + data.error);
            setProcessing(false);
            return;
        }

        const { id, clientSecret } = data;

        if (!clientSecret) {
            showMessage("Could not initialize payment. Please try again.");
            setProcessing(false);
            return;
        }

        payment_intent_id = id;

        const appearance = {
            theme: 'stripe',
            rules: {
                '.Label': {
                    fontWeight: 'bold',
                    textTransform: 'uppercase',
                }
            }
        };

        elements = stripe.elements({ clientSecret, appearance });

        // Clear any previous payment element
        document.querySelector("#payment-element").innerHTML = '';

        const paymentElement = elements.create("payment");
        paymentElement.mount("#payment-element");

    } catch (err) {
        showMessage("Network error: " + err.message);
    }

    setProcessing(false);
}

// ── Handle payment form submission ───────────────────────────────────────────
async function handleSubmit(e) {
    e.preventDefault();

    if (!elements) {
        showMessage("Payment not initialized. Please select a plan first.");
        return;
    }

    setLoading(true);

    let emailz = document.getElementById("email").value;
    let namez  = document.getElementById("customer_name").value;

    try {
        // Create customer and attach to payment intent
        const custResponse = await fetch(createOrderUrl, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                request_type: 'create_customer',
                payment_intent_id: payment_intent_id,
                name: namez,
                email: emailz,
            }),
        });

        const custData = await custResponse.json();

        if (custData.error) {
            showMessage("Error: " + custData.error);
            setLoading(false);
            return;
        }

        const customer_id = custData.customer_id || '';

        // Confirm the payment
        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: returnUrl + '&customer_id=' + customer_id,
            },
        });

        // Only reached on immediate error (card declined etc.)
        if (error) {
            if (error.type === "card_error" || error.type === "validation_error") {
                showMessage(error.message);
            } else {
                showMessage("An unexpected error occurred. Please try again.");
            }
        }

    } catch (err) {
        showMessage("Network error: " + err.message);
    }

    setLoading(false);
}

// ── Check payment status after redirect back from Stripe ─────────────────────
async function checkStatus() {
    const clientSecret = new URLSearchParams(window.location.search).get("payment_intent_client_secret");
    const customerID   = new URLSearchParams(window.location.search).get("customer_id");

    if (!clientSecret) return;

    try {
        const { paymentIntent } = await stripe.retrievePaymentIntent(clientSecret);

        if (!paymentIntent) {
            showMessage("Something went wrong.");
            return;
        }

        switch (paymentIntent.status) {
            case "succeeded":
                showMessage("Payment succeeded! Crediting your account...");
                // ── FIX: send paymentIntent.id (string), not the whole object ──
                await fetch(createOrderUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        request_type: 'payment_insert',
                        payment_intent: paymentIntent.id,
                        customer_id: customerID,
                    }),
                }).then((r) => r.json());

                showMessage("Payment successful! Your coins have been added.");
                break;

            case "processing":
                showMessage("Your payment is processing.");
                break;

            case "requires_payment_method":
                showMessage("Payment was not successful, please try again.");
                break;

            default:
                showMessage("Something went wrong.");
                break;
        }

    } catch (err) {
        showMessage("Error checking payment status: " + err.message);
    }
}

// ── UI Helpers ───────────────────────────────────────────────────────────────

function showMessage(messageText) {
    const messageContainer = document.querySelector("#payment-message");
    if (!messageContainer) { alert(messageText); return; }

    messageContainer.classList.remove("hide", "hidden");
    messageContainer.textContent = messageText;

    setTimeout(function () {
        messageContainer.classList.add("hide");
    }, 6000);
}

function setLoading(isLoading) {
    const btn     = document.querySelector("#submit");
    const spinner = document.querySelector("#spinner");
    if (btn)     btn.disabled = isLoading;
    if (spinner) spinner.classList.toggle("hide", !isLoading);
}

function setProcessing(isProcessing) {
    const spinner = document.querySelector("#spinner");
    if (spinner) spinner.classList.toggle("hide", !isProcessing);
}
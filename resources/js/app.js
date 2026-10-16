const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const app = document.body;

function readThemePreference() {
    try {
        const storedTheme = localStorage.getItem('atlas-theme');
        return storedTheme === 'light' || storedTheme === 'dark' ? storedTheme : null;
    } catch {
        return null;
    }
}

function setTheme(theme, persist = false) {
    const normalizedTheme = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = normalizedTheme;
    document.documentElement.style.colorScheme = normalizedTheme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', normalizedTheme === 'light' ? '#edf2f6' : '#09111f');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const nextTheme = normalizedTheme === 'dark' ? 'light' : 'dark';
        button.textContent = normalizedTheme === 'dark' ? 'Light' : 'Dark';
        button.setAttribute('aria-label', `Switch to ${nextTheme} theme`);
        button.setAttribute('aria-pressed', String(normalizedTheme === 'light'));
    });

    if (persist) {
        try {
            localStorage.setItem('atlas-theme', normalizedTheme);
        } catch {
            console.warn('The theme changed, but this browser did not allow saving the preference.');
        }
    }
}

const systemTheme = window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
setTheme(readThemePreference() || systemTheme);
document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
});

function makeElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
}

function showToast(message, isError = false) {
    const previous = document.querySelector('[data-toast]');
    previous?.remove();
    const toast = makeElement('div', `toast${isError ? ' alert-error' : ''}`, message);
    toast.setAttribute('role', isError ? 'alert' : 'status');
    const close = makeElement('button', '', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', () => toast.remove());
    toast.append(close);
    document.querySelector('.content-wrap')?.prepend(toast);
    window.setTimeout(() => toast.remove(), 6000);
}

async function postJson(url, values) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(values),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validationMessage = result.errors ? Object.values(result.errors).flat()[0] : null;
        throw new Error(validationMessage || result.message || 'That request could not be completed. Please try again.');
    }
    return result;
}

function formValues(form) {
    return Object.fromEntries(new FormData(form).entries());
}

function resultBox(element, primary, detail, warning) {
    element.replaceChildren();
    element.classList.remove('hidden');
    element.append(makeElement('div', 'result-primary', primary));
    if (detail) element.append(makeElement('div', 'result-detail', detail));
    if (warning) element.append(makeElement('div', 'result-warning', warning));
}

function attachAsyncForm(selector, endpoint, onSuccess) {
    const form = document.querySelector(selector);
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        const originalLabel = submit.innerHTML;
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.textContent = 'Calculating…';
        try {
            const result = await postJson(endpoint, formValues(form));
            onSuccess(result);
        } catch (error) {
            showToast(error.message, true);
        } finally {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            submit.innerHTML = originalLabel;
        }
    });
}

document.querySelectorAll('[data-currency-name-for]').forEach((label) => {
    const form = label.closest('form');
    const selector = form?.querySelector(`[name="${label.dataset.currencyNameFor}"]`);
    const updateLabel = () => {
        const option = selector?.selectedOptions[0];
        label.textContent = option?.value ? option.textContent.trim() : (label.dataset.emptyLabel || 'Choose a currency');
    };

    selector?.addEventListener('change', updateLabel);
    updateLabel();
});

const firstTimeGuide = document.querySelector('[data-first-time-guide]');
try {
    if (firstTimeGuide && localStorage.getItem('atlas-guide-dismissed') === 'true') {
        firstTimeGuide.hidden = true;
    }
} catch {
    firstTimeGuide?.removeAttribute('hidden');
}

document.querySelector('[data-dismiss-guide]')?.addEventListener('click', () => {
    if (!firstTimeGuide) return;
    firstTimeGuide.hidden = true;
    try {
        localStorage.setItem('atlas-guide-dismissed', 'true');
    } catch {
        console.warn('The guide was hidden, but this browser did not allow saving that preference.');
    }
});

const convertResult = document.querySelector('#convert-result');
attachAsyncForm('#convert-form', app.dataset.currencyEndpoint, (result) => {
    resultBox(
        convertResult,
        `${result.to_currency} ${result.converted_amount}`,
        `${result.amount} ${result.from_currency} × ${result.rate} · ${result.source} · calculated ${new Date(result.retrieved_at).toLocaleString()}`,
        'Indicative demonstration math only. This is not a live rate or a transfer offer.',
    );
});

const remittanceResult = document.querySelector('#remittance-result');
attachAsyncForm('#remittance-form', app.dataset.remittanceEndpoint, (result) => {
    remittanceResult.replaceChildren();
    remittanceResult.classList.remove('hidden');
    const list = makeElement('div', 'quote-list');
    result.quotes.forEach((quote) => {
        const row = makeElement('div', 'quote-row');
        row.append(makeElement('b', '', `#${quote.rank} · ${quote.name}`));
        row.append(makeElement('strong', '', `${result.reference.to_currency} ${quote.recipient_amount} received`));
        row.append(makeElement('small', '', `Fee ${quote.fee} ${quote.fee_currency} · ${quote.delivery_estimate} · ${quote.limitation}`));
        list.append(row);
    });
    remittanceResult.append(list);
    if (result.reference) {
        remittanceResult.append(makeElement(
            'div',
            'result-detail',
            `Reference rate: 1 ${result.reference.from_currency} = ${result.reference.rate} ${result.reference.to_currency} · ${result.reference.source} · calculated ${new Date(result.reference.retrieved_at).toLocaleString()}`,
        ));
    }
    remittanceResult.append(makeElement('div', 'result-warning', result.source));
});

const scenarioResult = document.querySelector('#scenario-result');
attachAsyncForm('#scenario-form', app.dataset.scenarioEndpoint, (result) => {
    scenarioResult.replaceChildren();
    scenarioResult.classList.remove('hidden');
    const cards = [
        ['Monthly remaining', `${result.budget_currency} ${result.budget.projected_remaining} · ${result.budget.status.replace('_', ' ')}`],
        ['Tuition saving pace', `${result.budget_currency} ${result.budget.required_tuition_savings} / month · ${result.budget_currency} ${result.budget.tuition_savings_gap} short`],
        ['Recipient under rate scenario', `${result.exchange.to_currency} ${result.exchange.scenario_amount}`],
    ];
    const nextInstallment = result.installments.find((installment) => installment.status !== 'funded');
    if (nextInstallment) cards.push(['Next tuition installment', `${result.exchange.from_currency} ${nextInstallment.amount} · ${new Date(`${nextInstallment.due_date}T00:00:00`).toLocaleDateString()}`]);
    cards.forEach(([label, value]) => {
        const card = makeElement('div');
        card.append(makeElement('small', '', label), makeElement('b', '', value));
        scenarioResult.append(card);
    });
    const forecastGrid = makeElement('div', 'forecast-grid');
    result.forecasts.forEach((forecast) => {
        const card = makeElement('article', `forecast-card forecast-${forecast.name.toLowerCase()}`);
        card.append(
            makeElement('div', 'forecast-heading', `${forecast.name} · ${forecast.rate_change_percent > 0 ? '+' : ''}${forecast.rate_change_percent}% FX`),
            makeElement('small', '', `Projected tuition fund · ${result.budget_currency}`),
            makeElement('strong', '', `${result.budget_currency} ${forecast.projected_tuition_fund}`),
            makeElement('small', '', `Tuition shortfall · ${result.budget_currency} ${forecast.tuition_shortfall}`),
        );
        const progress = makeElement('div', 'forecast-progress');
        const bar = makeElement('i');
        const target = Number(forecast.tuition_target);
        const fund = Number(forecast.projected_tuition_fund);
        const percent = target > 0 ? Math.min(100, Math.round((fund / target) * 100)) : 0;
        bar.style.width = `${percent}%`;
        progress.setAttribute('role', 'progressbar');
        progress.setAttribute('aria-label', `${forecast.name} tuition target funded`);
        progress.setAttribute('aria-valuenow', String(percent));
        progress.setAttribute('aria-valuemin', '0');
        progress.setAttribute('aria-valuemax', '100');
        progress.append(bar);
        card.append(progress);
        card.append(
            makeElement('small', '', `Contributions ${result.budget_currency} ${forecast.total_contributions} · cumulative net cash flow ${result.budget_currency} ${forecast.projected_cash_flow}`),
            makeElement('small', '', forecast.scholarship_received ? 'Includes the unconfirmed scholarship assumption.' : 'Scholarship not counted within this forecast.'),
        );
        forecastGrid.append(card);
    });
    scenarioResult.append(forecastGrid);
    const assumptions = result.forecast_assumptions;
    const conversion = assumptions.tuition_conversion;
    scenarioResult.append(makeElement(
        'p',
        '',
        `${result.assumption} Forecast: ${assumptions.months} months; rent ${assumptions.rent_change_percent > 0 ? '+' : ''}${assumptions.rent_change_percent}%; income delay ${assumptions.income_delay_months} month(s); FX stress ±${assumptions.rate_stress_percent}%. Monthly amounts ${assumptions.budget_input_currency}→${assumptions.budget_currency} at ${assumptions.budget_input_conversion.rate} from ${assumptions.budget_input_conversion.source} · calculated ${new Date(assumptions.budget_input_conversion.retrieved_at).toLocaleString()}. Tuition conversion ${conversion.from_currency}→${conversion.to_currency} at ${conversion.rate} from ${conversion.source} · calculated ${new Date(conversion.retrieved_at).toLocaleString()}. Recipient estimate: ${result.exchange.to_currency} ${result.exchange.scenario_amount} at ${result.exchange.rate_change_percent > 0 ? '+' : ''}${result.exchange.rate_change_percent}% after fee ${result.exchange.from_currency} ${result.transfer_fee}; ${result.exchange.source}.`,
    ));
});

document.querySelectorAll('[data-rate-slider]').forEach((slider) => {
    slider.addEventListener('input', () => {
        const display = document.querySelector('#rate-value');
        if (display) display.textContent = `${Number(slider.value) > 0 ? '+' : ''}${slider.value}%`;
    });
});

document.querySelectorAll('[data-stress-slider]').forEach((slider) => {
    slider.addEventListener('input', () => {
        const display = document.querySelector('#stress-value');
        if (display) display.textContent = `±${slider.value}%`;
    });
});

document.querySelectorAll('[data-open-form]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = document.getElementById(button.dataset.openForm);
        form?.classList.toggle('is-open');
        button.setAttribute('aria-expanded', String(form?.classList.contains('is-open') ?? false));
        form?.querySelector('input')?.focus();
    });
});

document.querySelectorAll('[data-dismiss-toast]').forEach((button) => {
    button.addEventListener('click', () => button.closest('[data-toast]')?.remove());
});

document.querySelector('[data-menu-toggle]')?.addEventListener('click', () => {
    const sidebar = document.querySelector('#sidebar');
    sidebar?.classList.toggle('is-open');
    const isOpen = sidebar?.classList.contains('is-open') ?? false;
    document.querySelector('[data-menu-toggle]')?.setAttribute('aria-expanded', String(isOpen));
});

document.querySelectorAll('.side-nav .nav-item').forEach((link) => {
    link.addEventListener('click', () => {
        document.querySelectorAll('.side-nav .nav-item').forEach((item) => item.classList.remove('active'));
        link.classList.add('active');
        document.querySelector('#sidebar')?.classList.remove('is-open');
    });
});

const chatForm = document.querySelector('#chat-form');
const chatInput = document.querySelector('#chat-input');
const chatMessages = document.querySelector('#chat-messages');
let conversationId = chatMessages?.dataset.conversationId || '';
const currencyNames = JSON.parse(app.dataset.currencyNames || '{}');

function labeledCurrency(code) {
    return `${code} — ${currencyNames[code] || 'currency name unavailable'}`;
}

function appendMessage(role, content) {
    if (!chatMessages) return null;
    chatMessages.querySelector('.chat-welcome')?.remove();
    const message = makeElement('article', `chat-message ${role === 'assistant' ? 'assistant-message' : 'user-message'}`);
    message.append(makeElement('span', 'message-avatar', role === 'assistant' ? 'A' : document.querySelector('.user-copy b')?.textContent?.slice(0, 1) || 'Y'));
    const body = makeElement('div');
    body.append(makeElement('b', '', role === 'assistant' ? 'Atlas' : 'You'));
    body.append(makeElement('p', '', content));
    message.append(body);
    chatMessages.append(message);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return message;
}

function appendActionSummary(container, action) {
    const argumentsData = action.arguments || {};
    const before = action.before_state || {};
    const rows = [];
    const amount = (value, currency) => `${labeledCurrency(currency)} ${value}`;

    if (action.name === 'create_savings_goal') {
        rows.push(['New savings goal', argumentsData.title]);
        rows.push(['Target amount', amount(argumentsData.target_amount, argumentsData.currency)]);
        rows.push(['Already saved', amount(argumentsData.current_amount || '0.00', argumentsData.currency)]);
        if (argumentsData.target_date) rows.push(['Target date', argumentsData.target_date]);
    } else if (action.name === 'create_budget') {
        rows.push(['Monthly category budget', argumentsData.category]);
        rows.push(['Monthly limit', amount(argumentsData.amount, argumentsData.currency)]);
    } else if (action.name === 'save_plan') {
        rows.push(['New planning note', argumentsData.title]);
        rows.push(['Summary', argumentsData.summary]);
    } else if (action.name === 'update_savings_goal') {
        rows.push(['Savings goal', before.title]);
        rows.push(['Current target', amount(before.target_amount, before.currency)]);
        rows.push(['Proposed target', amount(argumentsData.target_amount, before.currency)]);
        rows.push(['Saved progress remains', amount(before.current_amount, before.currency)]);
    } else if (action.name === 'update_tuition_plan') {
        rows.push(['Tuition plan', before.title]);
        rows.push(['Current tuition amount', amount(before.amount, before.currency)]);
        rows.push(['Proposed tuition amount', amount(argumentsData.amount || before.amount, before.currency)]);
        rows.push(['Current recorded savings', amount(before.saved_amount, before.currency)]);
        rows.push(['Proposed recorded savings', amount(argumentsData.saved_amount || before.saved_amount, before.currency)]);
        rows.push(['Current due date', before.due_date]);
        rows.push(['Proposed due date', argumentsData.due_date || before.due_date]);
        rows.push(['Current installments', String(before.installments)]);
        rows.push(['Proposed installments', String(argumentsData.installments || before.installments)]);
    }

    const details = makeElement('dl', 'action-summary');
    rows.forEach(([label, value]) => {
        const row = makeElement('div', 'action-summary-row');
        row.append(makeElement('dt', '', label), makeElement('dd', '', String(value ?? 'Not provided')));
        details.append(row);
    });
    container.append(details);
    const notice = action.status === 'confirmed'
        ? 'This confirmed change was saved only to your Atlas account. No payment was authorized or made.'
        : action.status === 'cancelled' || action.status === 'expired'
            ? 'No planning changes were saved. This draft cannot be used again.'
            : 'Confirming saves only this displayed change to your Atlas account. It does not authorize a payment or move money.';
    container.append(makeElement('p', 'action-disclaimer', notice));
}

function describeSavedOutcome(outcome) {
    if (!outcome || typeof outcome !== 'object') return '';
    const title = outcome.title || outcome.category || 'Planning record';

    if (outcome.record_type === 'savings_goal') {
        return `${title}: target ${labeledCurrency(outcome.currency)} ${outcome.target_amount}; saved progress ${labeledCurrency(outcome.currency)} ${outcome.current_amount}.`;
    }
    if (outcome.record_type === 'tuition_plan') {
        return `${title}: tuition ${labeledCurrency(outcome.currency)} ${outcome.amount}; recorded savings ${labeledCurrency(outcome.currency)} ${outcome.saved_amount}; due ${outcome.due_date}.`;
    }
    if (outcome.record_type === 'budget') {
        return `${title}: monthly limit ${labeledCurrency(outcome.currency)} ${outcome.amount}, for ${outcome.period_start} to ${outcome.period_end}.`;
    }
    return title ? `Saved “${title}” to your Atlas account.` : 'Saved to your Atlas account.';
}

function addToolResults(results, pendingActions = [], canConfirm = false, afterMessage = null) {
    if (!chatMessages) return;
    if (!Array.isArray(results)) return;
    let insertionPoint = afterMessage;
    const insertCard = (card) => {
        if (insertionPoint?.parentElement === chatMessages) {
            chatMessages.insertBefore(card, insertionPoint.nextSibling);
        } else {
            chatMessages.append(card);
        }
        insertionPoint = card;
    };
    results.filter((entry) => entry && typeof entry.name === 'string' && entry.result && typeof entry.result === 'object').forEach(({ name, result }) => {
        const card = makeElement('div', 'tool-result-card');
        const action = result.pending_action;
        if (action) {
            const statusTitle = action.status === 'confirmed'
                ? 'Change saved to your account'
                : action.status === 'cancelled'
                    ? 'Change discarded'
                    : action.status === 'expired'
                        ? 'Draft expired'
                        : 'Review this proposed change';
            card.append(makeElement('b', '', statusTitle));
            appendActionSummary(card, action);
            if (action.status_message) card.append(makeElement('p', 'result-detail', action.status_message));
            if (action.status === 'confirmed' && action.outcome) {
                card.append(makeElement('p', 'result-detail', describeSavedOutcome(action.outcome)));
            }
        } else {
            card.append(makeElement('b', '', `Atlas calculation · ${String(name).replaceAll('_', ' ')}`));
            const detail = makeElement('details', 'tool-result-details');
            detail.append(makeElement('summary', '', 'Review calculation details'));
            detail.append(makeElement('pre', '', JSON.stringify(result, null, 2)));
            card.append(detail);
        }
        if (canConfirm && action && !action.status) {
            const confirmButton = makeElement('button', 'button button-primary', 'Confirm and save');
            confirmButton.type = 'button';
            confirmButton.addEventListener('click', async () => {
                confirmButton.disabled = true;
                try {
                    const saved = await postJson(app.dataset.confirmEndpoint, {
                        conversation_id: conversationId,
                        pending_action_id: action.id,
                        confirmed: true,
                    });
                    confirmButton.textContent = 'Saved';
                    discardButton.disabled = true;
                    card.append(makeElement('div', 'result-detail', saved.message));
                    showToast(saved.message);
                    const url = new URL(window.location.href);
                    if (conversationId) url.searchParams.set('conversation_id', conversationId);
                    window.location.assign(url.toString());
                } catch (error) {
                    confirmButton.disabled = false;
                    showToast(error.message, true);
                }
            });
            card.append(confirmButton);
            const discardButton = makeElement('button', 'button button-quiet', 'Not now');
            discardButton.type = 'button';
            discardButton.addEventListener('click', async () => {
                discardButton.disabled = true;
                try {
                    const discarded = await postJson(app.dataset.cancelEndpoint, {
                        conversation_id: conversationId,
                        pending_action_id: action.id,
                        discarded: true,
                    });
                    confirmButton.disabled = true;
                    discardButton.textContent = 'Discarded';
                    card.append(makeElement('div', 'result-detail', discarded.message));
                    const url = new URL(window.location.href);
                    if (conversationId) url.searchParams.set('conversation_id', conversationId);
                    window.location.assign(url.toString());
                } catch (error) {
                    discardButton.disabled = false;
                    showToast(error.message, true);
                }
            });
            card.append(discardButton);
        }
        insertCard(card);
    });
    pendingActions.forEach((action) => {
        if (!results.some(({ result }) => result.pending_action?.name === action.name)) {
            const card = makeElement('div', 'tool-result-card');
            card.append(makeElement('b', '', 'Review this proposed change'));
            appendActionSummary(card, action);
            insertCard(card);
        }
    });

    chatMessages.scrollTop = chatMessages.scrollHeight;
}

document.querySelectorAll('[data-pending-action]').forEach((summary) => {
    try {
        appendActionSummary(summary, JSON.parse(summary.dataset.pendingAction || '{}'));
    } catch {
        summary.textContent = 'This proposed change could not be displayed. Reload before taking action.';
    }
});

document.querySelectorAll('.chat-message[data-tool-results]').forEach((message) => {
    try {
        addToolResults(JSON.parse(message.dataset.toolResults || '[]'), [], false, message);
    } catch {
        showToast('Saved tool details could not be displayed. Reload the conversation to try again.', true);
    }
});

chatForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;
    appendMessage('user', message);
    chatInput.value = '';
    const send = chatForm.querySelector('button[type="submit"]');
    send.disabled = true;
    const loading = makeElement('article', 'chat-message assistant-message');
    loading.append(makeElement('span', 'message-avatar', 'A'));
    const loadingBody = makeElement('div');
    loadingBody.append(makeElement('b', '', 'Atlas'), makeElement('p', '', 'Thinking through your question…'));
    loading.append(loadingBody);
    chatMessages.append(loading);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    try {
        const result = await postJson(app.dataset.agentEndpoint, { message, conversation_id: conversationId || null });
        conversationId = String(result.conversation_id);
        chatMessages.dataset.conversationId = conversationId;
        loading.remove();
        appendMessage('assistant', result.text);
        addToolResults(result.tool_results || [], result.pending_actions || [], true);
    } catch (error) {
        loading.remove();
        appendMessage('assistant', error.message);
    } finally {
        send.disabled = false;
        chatInput.focus();
    }
});

chatInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        chatForm?.requestSubmit();
    }
});

document.querySelectorAll('[data-prompt]').forEach((button) => {
    button.addEventListener('click', () => {
        if (chatInput) {
            chatInput.value = button.dataset.prompt || '';
            chatInput.focus();
        }
    });
});

document.querySelector('[data-new-chat]')?.addEventListener('click', () => {
    conversationId = '';
    if (chatMessages) {
        chatMessages.dataset.conversationId = '';
        chatMessages.replaceChildren();
        const welcome = makeElement('div', 'chat-welcome');
        welcome.append(makeElement('span', 'welcome-spark', '✳'), makeElement('h3', '', 'What’s on your mind?'), makeElement('p', '', 'Send a question to start a new private conversation.'));
        chatMessages.append(welcome);
    }
    document.querySelectorAll('.conversation-item').forEach((item) => item.classList.remove('selected'));
    chatInput?.focus();
});

document.querySelectorAll('[data-load-conversation]').forEach((button) => {
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const response = await fetch(`/agent/conversations/${encodeURIComponent(button.dataset.loadConversation)}`, {
                headers: { 'Accept': 'application/json' },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Conversation could not be loaded.');
            conversationId = String(result.conversation_id);
            chatMessages.dataset.conversationId = conversationId;
            chatMessages.replaceChildren();
            result.messages.forEach((message) => {
                const renderedMessage = appendMessage(message.role, message.content);
                if (message.role === 'assistant' && Array.isArray(message.tool_result)) {
                    addToolResults(message.tool_result, [], false, renderedMessage);
                }
            });
            document.querySelectorAll('.conversation-item').forEach((item) => item.classList.toggle('selected', item === button));
            document.querySelector('#assistant')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showToast(error.message, true);
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('[data-approve-pending], [data-reject-pending]').forEach((button) => {
    button.addEventListener('click', async () => {
        const approval = button.closest('[data-pending-approval]');
        const isApproval = button.hasAttribute('data-approve-pending');
        const actionId = button.dataset.approvePending || button.dataset.rejectPending;
        const controls = approval?.querySelectorAll('button') || [button];
        controls.forEach((control) => { control.disabled = true; });

        try {
            const response = await postJson(isApproval ? app.dataset.confirmEndpoint : app.dataset.cancelEndpoint, {
                conversation_id: button.dataset.conversationId,
                pending_action_id: actionId,
                [isApproval ? 'confirmed' : 'discarded']: true,
            });
            showToast(response.message);
            const url = new URL(window.location.href);
            url.searchParams.set('conversation_id', button.dataset.conversationId);
            window.location.assign(url.toString());
        } catch (error) {
            controls.forEach((control) => { control.disabled = false; });
            showToast(error.message, true);
        }
    });
});

document.querySelectorAll('[data-toast]').forEach((toast) => {
    window.setTimeout(() => toast.remove(), 6500);
});

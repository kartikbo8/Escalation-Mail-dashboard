        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const widget = document.querySelector('.assistant-widget');
        if (!widget) return;

        const panel = widget.querySelector('.assistant-widget-panel');
        const launcher = widget.querySelector('.assistant-widget-launcher');
        const closeButton = widget.querySelector('.assistant-widget-close');
        const form = widget.querySelector('.assistant-widget-form');
        const input = widget.querySelector('#assistant-widget-input');
        const messages = widget.querySelector('.assistant-widget-messages');
        const status = widget.querySelector('.assistant-widget-status');
        const endpoint = widget.dataset.chatUrl;
        const csrfToken = widget.dataset.csrfToken;
        const openStateKey = 'assistantWidgetOpen';
        const navigationEntry = performance.getEntriesByType('navigation')[0];
        let historyClearPromise = Promise.resolve(true);

        const setOpen = (isOpen, focusInput = true) => {
            panel.hidden = !isOpen;
            launcher.setAttribute('aria-expanded', String(isOpen));
            if (isOpen) {
                sessionStorage.setItem(openStateKey, 'true');
                if (focusInput) input.focus();
            } else {
                sessionStorage.removeItem(openStateKey);
            }
        };

        const addMessage = (text, role) => {
            const empty = messages.querySelector('.assistant-widget-empty');
            if (empty) empty.remove();
            const bubble = document.createElement('div');
            bubble.className = `assistant-widget-message ${role === 'user' ? 'is-user' : 'is-assistant'}`;
            bubble.textContent = text;
            messages.appendChild(bubble);
            messages.scrollTop = messages.scrollHeight;
            return bubble;
        };

        if (sessionStorage.getItem(openStateKey) === 'true') {
            setOpen(true, false);
        }

        if (navigationEntry && navigationEntry.type === 'reload') {
            while (messages.firstChild) {
                messages.removeChild(messages.firstChild);
            }
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'assistant-widget-empty';
            emptyMessage.textContent = 'Hi! Ask me about app errors or how the escalation workflow works.';
            messages.appendChild(emptyMessage);
            status.textContent = 'Clearing chat...';
            historyClearPromise = fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                },
                body: new URLSearchParams({
                    action: 'clear_history',
                    csrf_token: csrfToken,
                }),
            }).then(async (response) => {
                const result = await response.json();
                if (!response.ok || result.cleared !== true) {
                    throw new Error(result.error || 'Could not clear the chat. Please reload the page.');
                }
                status.textContent = '';
                return true;
            }).catch((error) => {
                status.textContent = error.message || 'Could not clear the chat. Please reload the page.';
                return false;
            });
        }

        launcher.addEventListener('click', () => setOpen(panel.hidden));
        closeButton.addEventListener('click', () => {
            setOpen(false);
            launcher.focus();
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const question = input.value.trim();
            if (!question || form.dataset.busy === 'true') return;

            form.dataset.busy = 'true';
            form.querySelector('button[type="submit"]').disabled = true;
            const historyCleared = await historyClearPromise;
            if (!historyCleared) {
                form.dataset.busy = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
                input.focus();
                return;
            }

            addMessage(question, 'user');
            input.value = '';
            status.textContent = 'Thinking...';

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({
                        action: 'chat',
                        question,
                        csrf_token: csrfToken,
                    }),
                });
                const result = await response.json();
                if (!response.ok || typeof result.answer !== 'string') {
                    throw new Error(result.error || 'Could not get a reply. Please try again.');
                }
                addMessage(result.answer, 'assistant');
                if (typeof result.download_url === 'string'
                    && /^daily_ticket_export\.php\?date=\d{4}-\d{2}-\d{2}$/.test(result.download_url)) {
                    const link = document.createElement('a');
                    link.className = 'assistant-widget-download';
                    link.href = result.download_url;
                    link.textContent = typeof result.download_label === 'string'
                        ? result.download_label
                        : 'Download report';
                    link.setAttribute('download', '');
                    messages.appendChild(link);
                }
                if (result.action_url === 'admin_assistant.php') {
                    const link = document.createElement('a');
                    link.className = 'assistant-widget-download';
                    link.href = result.action_url;
                    link.textContent = typeof result.action_label === 'string'
                        ? result.action_label
                        : 'Review and confirm';
                    messages.appendChild(link);
                }
                status.textContent = '';
            } catch (error) {
                status.textContent = error.message || 'Could not get a reply. Please try again.';
                input.value = question;
            } finally {
                form.dataset.busy = 'false';
                form.querySelector('button[type="submit"]').disabled = false;
                messages.scrollTop = messages.scrollHeight;
                input.focus();
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                launcher.focus();
            }
        });
    })();

    document.querySelectorAll('form[method="get"] input[type="search"][name]').forEach((searchInput) => {
        const searchForm = searchInput.form;
        if (!searchForm) return;

        let hadSearchTerm = searchInput.value.trim() !== '';
        searchInput.addEventListener('input', () => {
            const hasSearchTerm = searchInput.value.trim() !== '';
            if (hadSearchTerm && !hasSearchTerm) {
                if (typeof searchForm.requestSubmit === 'function') {
                    searchForm.requestSubmit();
                } else {
                    searchForm.submit();
                }
            }
            hadSearchTerm = hasSearchTerm;
        });
    });
</script>
</body>
</html>

(function () {
    'use strict';

    const MAX_BYTES = 268435456;
    const ACCEPT = {
        image: 'image/jpeg,image/png,image/webp,image/gif',
        video: 'video/mp4,video/quicktime,video/webm,video/3gpp',
        audio: 'audio/mpeg,audio/mp4,audio/ogg,audio/opus,audio/wav,audio/x-wav,audio/aac',
        document: '.pdf,.zip,.json,.xml,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.html'
    };

    class Composer {
        constructor(options) {
            this.form = options.form;
            this.endpoint = options.endpoint;
            this.onQueued = options.onQueued || function () {};
            this.mode = 'text';
            this.file = null;
            this.modes = [...this.form.querySelectorAll('[data-composer-mode]')];
            this.panel = this.form.querySelector('#chat-media-panel');
            this.fileInput = this.form.querySelector('#chat-media-file');
            this.dropzone = this.form.querySelector('#chat-media-dropzone');
            this.selectedFile = this.form.querySelector('#chat-selected-file');
            this.urlInput = this.form.querySelector('#chat-media-url');
            this.textInput = this.form.querySelector('#chat-text');
            this.status = this.form.querySelector('#chat-composer-status');
            this.sendButton = this.form.querySelector('#chat-send-button');
            this.bind();
            this.selectMode('text');
        }

        bind() {
            this.modes.forEach(button => button.addEventListener('click', () => this.selectMode(button.dataset.composerMode)));
            this.dropzone.addEventListener('click', () => this.fileInput.click());
            this.dropzone.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    this.fileInput.click();
                }
            });
            this.fileInput.addEventListener('change', () => this.setFile(this.fileInput.files[0] || null));
            this.urlInput.addEventListener('input', () => {
                if (this.urlInput.value.trim() && this.file) this.clearFile();
                this.setStatus('');
            });
            ['dragenter', 'dragover'].forEach(name => this.dropzone.addEventListener(name, event => {
                event.preventDefault();
                this.dropzone.classList.add('is-dragging');
            }));
            ['dragleave', 'drop'].forEach(name => this.dropzone.addEventListener(name, event => {
                event.preventDefault();
                this.dropzone.classList.remove('is-dragging');
            }));
            this.dropzone.addEventListener('drop', event => this.setFile(event.dataTransfer.files[0] || null));
            this.form.addEventListener('submit', event => this.submit(event));
        }

        selectMode(mode) {
            if (!['text', 'image', 'video', 'audio', 'document'].includes(mode)) return;
            this.mode = mode;
            this.modes.forEach(button => {
                const selected = button.dataset.composerMode === mode;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            const isMedia = mode !== 'text';
            this.panel.hidden = !isMedia;
            this.form.querySelector('#chat-media-type').value = isMedia ? mode : '';
            this.fileInput.accept = isMedia ? ACCEPT[mode] : '';
            this.textInput.placeholder = isMedia ? 'Legenda opcional' : 'Digite uma mensagem';
            if (!isMedia) {
                this.clearFile();
                this.urlInput.value = '';
            }
            this.setStatus('');
        }

        setFile(file) {
            if (!file) return this.clearFile();
            this.clearFile();
            if (file.size > MAX_BYTES) return this.fail('O arquivo excede o limite de 256 MB.');
            if (this.mode === 'text') return this.fail('Selecione um tipo de midia antes do arquivo.');
            if (this.mode !== 'document' && !String(file.type).startsWith(`${this.mode}/`)) {
                return this.fail(`O arquivo nao corresponde ao modo ${this.mode}.`);
            }
            this.file = file;
            this.urlInput.value = '';
            this.selectedFile.hidden = false;
            this.selectedFile.innerHTML = `<i class="fas fa-paperclip"></i><span>${this.escape(file.name)}</span><small>${this.formatBytes(file.size)}</small><button type="button" aria-label="Remover arquivo" title="Remover arquivo"><i class="fas fa-xmark"></i></button>`;
            this.selectedFile.querySelector('button').addEventListener('click', () => this.clearFile());
            this.setStatus('Arquivo pronto para envio.');
        }

        clearFile() {
            this.file = null;
            this.fileInput.value = '';
            this.selectedFile.hidden = true;
            this.selectedFile.innerHTML = '';
        }

        async submit(event) {
            event.preventDefault();
            const to = this.form.querySelector('#chat-to').value.trim();
            const chatType = this.form.querySelector('#chat-type').value;
            const text = this.textInput.value.trim();
            const mediaUrl = this.urlInput.value.trim();
            if (!to) return this.fail('Informe o destino da mensagem.');
            if (this.mode === 'text' && !text) return this.fail('Digite uma mensagem.');
            if (this.mode !== 'text' && !this.file && !mediaUrl) return this.fail('Selecione um arquivo ou informe uma URL publica.');

            this.sendButton.disabled = true;
            this.setStatus(this.file ? 'Enviando arquivo...' : 'Adicionando a fila...');
            try {
                let request;
                if (this.mode === 'text') {
                    request = fetch(this.endpoint, {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({to, chat_type: chatType, text})
                    });
                } else {
                    const body = new FormData();
                    body.append('to', to);
                    body.append('chat_type', chatType);
                    body.append('media_type', this.mode);
                    if (text) body.append('caption', text);
                    if (this.file) body.append('media', this.file, this.file.name);
                    else body.append('media_url', mediaUrl);
                    request = fetch(this.endpoint, {method: 'POST', body});
                }
                const response = await request;
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) throw new Error(data.error || 'Falha ao adicionar a mensagem a fila.');
                this.textInput.value = '';
                this.urlInput.value = '';
                this.clearFile();
                this.setStatus('Mensagem adicionada a fila.');
                this.onQueued(data);
            } catch (error) {
                this.fail(error.message || 'Erro de conexao ao enviar.');
            } finally {
                this.sendButton.disabled = false;
            }
        }

        fail(message) {
            this.setStatus(message, true);
        }

        setStatus(message, error) {
            this.status.textContent = message;
            this.status.classList.toggle('is-error', Boolean(error));
        }

        formatBytes(bytes) {
            if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
            return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
        }

        escape(value) {
            const node = document.createElement('span');
            node.textContent = value;
            return node.innerHTML;
        }
    }

    window.ChatMediaComposer = {
        init(options) {
            if (!options || !options.form) throw new Error('Chat composer form is required');
            return new Composer(options);
        }
    };
})();

/* RCMAKER local captcha widget. No CDN, frontend framework or third-party service is required. */
(function (global) {
    "use strict";

    /** Bind one independent widget to a container; endpoints are configured by the application. */
    class RcmakerCaptcha {
        constructor(container, options) {
            this.root = typeof container === "string" ? document.querySelector(container) : container;
            if (!this.root) throw new Error("Captcha container was not found.");
            this.options = Object.assign({ inputName: "captcha", theme: "auto", credentials: "same-origin", timeout: 10000 }, options);
            if (!this.options.challengeUrl || !this.options.verifyUrl) throw new Error("Captcha endpoint URLs are required.");
            this.sequence = 0;
            this.token = "";
            this.destroyed = false;
            this.cleanups = [];
            this.root.classList.add("rc-captcha");
            this.root.dataset.theme = this.options.theme;
            this.root.innerHTML = `
                <div class="rc-captcha__heading">
                    <p class="rc-captcha__title">&#23433;&#20840;&#39564;&#35777;</p>
                    <button class="rc-captcha__refresh" type="button" title="&#25442;&#19968;&#24352;" aria-label="&#25442;&#19968;&#24352;">&#8635;</button>
                </div>
                <div class="rc-captcha__scene">
                    <img class="rc-captcha__background" alt="&#39564;&#35777;&#22270;&#29255;">
                    <img class="rc-captcha__piece" alt="">
                </div>
                <div class="rc-captcha__track" hidden>
                    <span class="rc-captcha__track-text">&#25302;&#21160;&#25340;&#22270;</span>
                    <button class="rc-captcha__thumb" type="button" aria-label="&#25302;&#21160;&#25340;&#22270;">&#8594;</button>
                </div>
                <input class="rc-captcha__input" type="text" autocomplete="off" aria-label="&#39564;&#35777;&#30721;" placeholder="&#36755;&#20837;&#39564;&#35777;&#30721;" hidden>
                <p class="rc-captcha__status" role="status" aria-live="polite"></p>
                <input class="rc-captcha__value" type="hidden">`;
            this.scene = this.root.querySelector(".rc-captcha__scene");
            this.background = this.root.querySelector(".rc-captcha__background");
            this.piece = this.root.querySelector(".rc-captcha__piece");
            this.track = this.root.querySelector(".rc-captcha__track");
            this.thumb = this.root.querySelector(".rc-captcha__thumb");
            this.refreshButton = this.root.querySelector(".rc-captcha__refresh");
            this.input = this.root.querySelector(".rc-captcha__input");
            this.hidden = this.root.querySelector(".rc-captcha__value");
            this.hidden.name = this.options.inputName;
            this.status = this.root.querySelector(".rc-captcha__status");
            this.listen(this.refreshButton, "click", () => this.reset());
            this.listen(this.input, "input", () => this.setToken(this.input.value));
            this.listen(this.thumb, "pointerdown", (event) => this.startDrag(event));
            this.listen(this.thumb, "pointermove", (event) => this.moveDrag(event));
            this.listen(this.thumb, "pointerup", (event) => this.endDrag(event));
            this.listen(this.thumb, "pointercancel", () => this.cancelDrag());
            this.reset();
        }

        /** Register removable handlers so replacing a widget does not leak listeners or active fetches. */
        listen(target, name, callback) {
            target.addEventListener(name, callback);
            this.cleanups.push(() => target.removeEventListener(name, callback));
        }

        /** Keep form state, public state and callbacks in agreement. */
        setToken(value) {
            this.token = value;
            this.hidden.value = value;
            if (typeof this.options.onChange === "function") this.options.onChange(value);
        }

        /** Present stable loading/success/error states without exposing server exceptions. */
        state(name, message) {
            this.root.dataset.state = name;
            this.status.textContent = message;
        }

        /** Bound network waits, abort on refresh, and never accept a stale response after a newer challenge. */
        async request(url, init, sequence) {
            const controller = new AbortController();
            this.controller = controller;
            const timer = setTimeout(() => controller.abort(), this.options.timeout);
            try {
                const response = await fetch(url, Object.assign({ credentials: this.options.credentials, signal: controller.signal, cache: "no-store" }, init));
                if (!response.ok) throw new Error("Captcha request failed.");
                const type = response.headers.get("content-type") || "";
                const body = type.startsWith("image/") ? await response.blob() : await response.json();
                if (sequence !== this.sequence || this.destroyed) throw new Error("Stale captcha response.");
                return { type, body, expires: Number(response.headers.get("x-captcha-expires-in")) || 0 };
            } finally {
                clearTimeout(timer);
            }
        }

        /** Reload either the legacy image response or a structured slider challenge from the same endpoint. */
        async reset() {
            if (this.destroyed) return;
            const sequence = ++this.sequence;
            if (this.controller) this.controller.abort();
            clearTimeout(this.expiry);
            this.cancelDrag();
            this.setToken("");
            this.data = null;
            this.input.value = "";
            this.input.disabled = false;
            this.input.hidden = true;
            this.track.hidden = true;
            this.piece.hidden = true;
            this.refreshButton.disabled = true;
            this.root.setAttribute("aria-busy", "true");
            this.state("loading", "\u52a0\u8f7d\u4e2d");
            if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
            try {
                const response = await this.request(this.options.challengeUrl, { headers: { Accept: "application/json, image/*" } }, sequence);
                const type = response.type;
                if (type.startsWith("image/")) {
                    const blob = response.body;
                    if (sequence !== this.sequence) return;
                    this.objectUrl = URL.createObjectURL(blob);
                    this.background.src = this.objectUrl;
                    await this.background.decode();
                    if (sequence !== this.sequence || this.destroyed) return;
                    this.scene.style.aspectRatio = this.background.naturalWidth + " / " + this.background.naturalHeight;
                    this.input.hidden = false;
                    if (response.expires > 0) this.expiry = setTimeout(() => this.invalidate(), response.expires * 1000);
                    this.state("ready", "");
                } else {
                    const data = response.body;
                    if (sequence !== this.sequence) return;
                    if (data.success !== true || data.type !== "slider" || !/^[a-f0-9]{64}$/.test(data.challenge_id)) throw new Error("Invalid slider challenge.");
                    for (const name of ["background", "piece"]) {
                        if (typeof data[name] !== "string" || !data[name].startsWith("data:image/png;base64,")) throw new Error("Invalid captcha image.");
                    }
                    this.data = data;
                    this.scene.style.aspectRatio = data.width + " / " + data.height;
                    this.background.src = data.background;
                    this.piece.src = data.piece;
                    await Promise.all([this.background.decode(), this.piece.decode()]);
                    if (sequence !== this.sequence || this.destroyed) return;
                    this.piece.style.width = (data.piece_width / data.width * 100) + "%";
                    this.piece.style.height = (data.piece_height / data.height * 100) + "%";
                    this.piece.style.top = (data.y / data.height * 100) + "%";
                    this.piece.hidden = false;
                    this.track.hidden = false;
                    this.thumb.disabled = false;
                    this.position(0);
                    this.expiry = setTimeout(() => this.invalidate(), data.expires_in * 1000);
                    this.state("ready", "");
                }
            } catch (error) {
                if (sequence === this.sequence && !this.destroyed) this.state("error", "\u52a0\u8f7d\u5931\u8d25\uff0c\u8bf7\u5237\u65b0\u91cd\u8bd5");
            } finally {
                if (sequence === this.sequence && !this.destroyed) {
                    this.refreshButton.disabled = false;
                    this.root.setAttribute("aria-busy", "false");
                }
            }
        }

        /** Map thumb movement to native image pixels; CSS scaling and image geometry use independent tracks. */
        position(offset) {
            if (!this.data) return;
            this.offset = Math.max(0, Math.min(this.data.width - this.data.piece_width, offset));
            this.piece.style.left = (this.offset / this.data.width * 100) + "%";
            const travel = Math.max(1, this.track.clientWidth - this.thumb.offsetWidth);
            this.thumb.style.transform = "translateX(" + (this.offset / (this.data.width - this.data.piece_width) * travel) + "px)";
        }

        /** Start a real pointer path; each widget maintains its own challenge and capture. */
        startDrag(event) {
            if (!this.data || this.thumb.disabled || event.button !== 0) return;
            event.preventDefault();
            this.thumb.setPointerCapture(event.pointerId);
            this.drag = { pointer: event.pointerId, x: event.clientX, y: event.clientY, start: performance.now(), points: [{ x: 0, y: this.data.height / 2, t: 0 }] };
            this.position(0);
        }

        /** Record actual position/time with finite bounded samples; never synthesize a human trail. */
        moveDrag(event) {
            if (!this.drag || event.pointerId !== this.drag.pointer) return;
            const travel = Math.max(1, this.track.clientWidth - this.thumb.offsetWidth);
            this.position((event.clientX - this.drag.x) / travel * (this.data.width - this.data.piece_width));
            const point = { x: this.offset, y: Math.max(0, Math.min(this.data.height, this.data.height / 2 + event.clientY - this.drag.y)), t: Math.round(performance.now() - this.drag.start) };
            const previous = this.drag.points[this.drag.points.length - 1];
            if (point.t > previous.t) {
                if (this.drag.points.length >= 199) this.drag.points = this.drag.points.filter((item, index) => index % 2 === 0);
                this.drag.points.push(point);
            }
        }

        /** Submit one coordinate attempt; only the server-issued proof can enable the business form. */
        async endDrag(event) {
            if (!this.drag || event.pointerId !== this.drag.pointer) return;
            this.moveDrag(event);
            const path = this.drag.points;
            this.cancelDrag();
            const sequence = this.sequence;
            this.thumb.disabled = true;
            this.state("checking", "\u9a8c\u8bc1\u4e2d");
            try {
                const body = new URLSearchParams({ challenge_id: this.data.challenge_id, offset: String(this.offset), track: JSON.stringify(path) });
                const response = await this.request(this.options.verifyUrl, {
                    method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" }, body
                }, sequence);
                const result = response.body;
                if (sequence !== this.sequence || this.destroyed) return;
                if (result.success !== true || !/^[a-f0-9]{64}$/.test(result.token)) throw new Error("Captcha failed.");
                this.setToken(result.token);
                clearTimeout(this.expiry);
                this.expiry = setTimeout(() => this.invalidate(), result.expires_in * 1000);
                this.state("success", "\u9a8c\u8bc1\u6210\u529f");
                if (typeof this.options.onSuccess === "function") this.options.onSuccess(result.token);
            } catch (error) {
                if (sequence === this.sequence && !this.destroyed) {
                    this.setToken("");
                    this.state("error", "\u9a8c\u8bc1\u5931\u8d25\uff0c\u8bf7\u5237\u65b0\u91cd\u8bd5");
                }
            }
        }

        /** Release capture and prevent an abandoned drag from mutating a refreshed challenge. */
        cancelDrag() {
            if (this.drag && this.thumb.hasPointerCapture(this.drag.pointer)) this.thumb.releasePointerCapture(this.drag.pointer);
            this.drag = null;
        }

        /** Clear expired values instead of silently allowing a form to reuse an old proof. */
        invalidate() {
            ++this.sequence;
            if (this.controller) this.controller.abort();
            this.cancelDrag();
            this.setToken("");
            this.input.value = "";
            this.input.disabled = true;
            this.thumb.disabled = true;
            this.state("error", "\u9a8c\u8bc1\u5df2\u8fc7\u671f\uff0c\u8bf7\u5237\u65b0");
        }

        /** Read the value for JSON submissions; ordinary forms use the generated hidden field. */
        getValue() { return this.token; }

        /** Dispose listeners, timers, object URLs and outstanding requests when removing a widget. */
        destroy() {
            this.destroyed = true;
            ++this.sequence;
            this.cancelDrag();
            if (this.controller) this.controller.abort();
            clearTimeout(this.expiry);
            if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
            this.cleanups.forEach((cleanup) => cleanup());
            this.setToken("");
            this.root.replaceChildren();
        }
    }

    global.RcmakerCaptcha = RcmakerCaptcha;
})(window);

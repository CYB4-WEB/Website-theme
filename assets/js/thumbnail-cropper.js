(function() {
    'use strict';

    const data = window.starterData || {};
    const ajaxUrl = data.ajaxUrl || '';
    const nonce = data.nonce || '';

    const PRESETS = {
        'manga-cover': { width: 200, height: 300, label: 'Manga Cover (2:3)' },
        'chapter-thumb': { width: 120, height: 90, label: 'Chapter Thumb (4:3)' },
        'banner': { width: 900, height: 300, label: 'Banner (3:1)' }
    };

    const MIN_ZOOM = 0.5;
    const MAX_ZOOM = 3;

    class StarterCropper {
        constructor(options) {
            this.options = Object.assign({
                preset: 'manga-cover',
                onCrop: null,
                targetInput: null
            }, options);

            this.image = null;
            this.canvas = null;
            this.ctx = null;
            this.previewCanvas = null;
            this.previewCtx = null;

            this.zoom = 1;
            this.imageX = 0;
            this.imageY = 0;
            this.imageWidth = 0;
            this.imageHeight = 0;
            this.naturalWidth = 0;
            this.naturalHeight = 0;

            this.cropX = 0;
            this.cropY = 0;
            this.cropW = 0;
            this.cropH = 0;

            this.isDraggingCrop = false;
            this.isResizing = false;
            this.resizeHandle = null;
            this.dragStartX = 0;
            this.dragStartY = 0;
            this.dragOffsetX = 0;
            this.dragOffsetY = 0;

            this.pinchStartDist = 0;
            this.pinchStartZoom = 1;

            this.modal = null;
            this.preset = PRESETS[this.options.preset] || PRESETS['manga-cover'];

            this._boundMouseMove = this.onMouseMove.bind(this);
            this._boundMouseUp = this.onMouseUp.bind(this);
            this._boundTouchMove = this.onTouchMove.bind(this);
            this._boundTouchEnd = this.onTouchEnd.bind(this);
        }

        /**
         * Open cropper with an image file
         */
        open(file) {
            if (!file) return;

            const self = this;
            const reader = new FileReader();

            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    self.image = img;
                    self.naturalWidth = img.naturalWidth;
                    self.naturalHeight = img.naturalHeight;
                    self.createModal();
                    self.initCanvas();
                    self.resetCrop();
                    self.render();
                };
                img.onerror = function() {
                    console.error('Failed to load image');
                };
                img.src = e.target.result;
            };

            reader.readAsDataURL(file);
        }

        /**
         * Create modal overlay
         */
        createModal() {
            if (this.modal) {
                this.modal.remove();
            }

            this.modal = document.createElement('div');
            this.modal.className = 'starter-cropper-modal';

            const presetOptions = Object.keys(PRESETS).map(function(key) {
                const p = PRESETS[key];
                return '<option value="' + key + '">' + p.label + '</option>';
            }).join('');

            this.modal.innerHTML = ''
                + '<div class="cropper-overlay"></div>'
                + '<div class="cropper-container">'
                +   '<div class="cropper-header">'
                +     '<h3 class="cropper-title">Crop Thumbnail</h3>'
                +     '<button class="cropper-close" title="Cancel">&times;</button>'
                +   '</div>'
                +   '<div class="cropper-body">'
                +     '<div class="cropper-main">'
                +       '<canvas class="cropper-canvas"></canvas>'
                +     '</div>'
                +     '<div class="cropper-sidebar">'
                +       '<div class="cropper-preview-section">'
                +         '<h4>Preview</h4>'
                +         '<div class="cropper-preview-card">'
                +           '<canvas class="cropper-preview-canvas"></canvas>'
                +         '</div>'
                +       '</div>'
                +       '<div class="cropper-controls">'
                +         '<label>Preset:</label>'
                +         '<select class="cropper-preset-select">' + presetOptions + '</select>'
                +         '<label>Zoom: <span class="cropper-zoom-value">1.0x</span></label>'
                +         '<input type="range" class="cropper-zoom-slider" min="' + MIN_ZOOM + '" max="' + MAX_ZOOM + '" step="0.1" value="1">'
                +       '</div>'
                +     '</div>'
                +   '</div>'
                +   '<div class="cropper-footer">'
                +     '<button class="cropper-btn cropper-btn-reset">Reset</button>'
                +     '<button class="cropper-btn cropper-btn-cancel">Cancel</button>'
                +     '<button class="cropper-btn cropper-btn-apply">Apply Crop</button>'
                +   '</div>'
                + '</div>';

            document.body.appendChild(this.modal);
            document.body.style.overflow = 'hidden';

            this.bindModalEvents();
        }

        /**
         * Bind modal UI events
         */
        bindModalEvents() {
            const self = this;

            // Close / Cancel
            this.modal.querySelector('.cropper-close').addEventListener('click', function() {
                self.close();
            });
            this.modal.querySelector('.cropper-overlay').addEventListener('click', function() {
                self.close();
            });
            this.modal.querySelector('.cropper-btn-cancel').addEventListener('click', function() {
                self.close();
            });

            // Reset
            this.modal.querySelector('.cropper-btn-reset').addEventListener('click', function() {
                self.zoom = 1;
                self.modal.querySelector('.cropper-zoom-slider').value = 1;
                self.modal.querySelector('.cropper-zoom-value').textContent = '1.0x';
                self.resetCrop();
                self.render();
            });

            // Apply
            this.modal.querySelector('.cropper-btn-apply').addEventListener('click', function() {
                self.applyCrop();
            });

            // Preset select
            const presetSelect = this.modal.querySelector('.cropper-preset-select');
            presetSelect.value = this.options.preset;
            presetSelect.addEventListener('change', function() {
                self.preset = PRESETS[this.value] || PRESETS['manga-cover'];
                self.resetCrop();
                self.render();
            });

            // Zoom slider
            const zoomSlider = this.modal.querySelector('.cropper-zoom-slider');
            zoomSlider.addEventListener('input', function() {
                self.zoom = parseFloat(this.value);
                self.modal.querySelector('.cropper-zoom-value').textContent = self.zoom.toFixed(1) + 'x';
                self.initCanvas();
                self.constrainCrop();
                self.render();
            });
        }

        /**
         * Initialize canvas and fit image
         */
        initCanvas() {
            this.canvas = this.modal.querySelector('.cropper-canvas');
            this.ctx = this.canvas.getContext('2d');
            this.previewCanvas = this.modal.querySelector('.cropper-preview-canvas');
            this.previewCtx = this.previewCanvas.getContext('2d');

            const container = this.canvas.parentElement;
            const maxW = container.clientWidth || 600;
            const maxH = container.clientHeight || 450;

            // Fit image into canvas with zoom
            const scale = Math.min(maxW / this.naturalWidth, maxH / this.naturalHeight) * this.zoom;
            this.imageWidth = Math.round(this.naturalWidth * scale);
            this.imageHeight = Math.round(this.naturalHeight * scale);

            const dpr = window.devicePixelRatio || 1;
            this.canvas.width = Math.max(this.imageWidth, maxW) * dpr;
            this.canvas.height = Math.max(this.imageHeight, maxH) * dpr;
            this.canvas.style.width = Math.max(this.imageWidth, maxW) + 'px';
            this.canvas.style.height = Math.max(this.imageHeight, maxH) + 'px';
            this.ctx.scale(dpr, dpr);

            this.imageX = Math.round((Math.max(this.imageWidth, maxW) - this.imageWidth) / 2);
            this.imageY = Math.round((Math.max(this.imageHeight, maxH) - this.imageHeight) / 2);

            // Preview canvas
            this.previewCanvas.width = this.preset.width;
            this.previewCanvas.height = this.preset.height;
            this.previewCanvas.style.width = Math.min(this.preset.width, 180) + 'px';
            this.previewCanvas.style.height = Math.min(this.preset.height, 270) + 'px';

            // Bind canvas events
            this.bindCanvasEvents();
        }

        /**
         * Reset crop area to center
         */
        resetCrop() {
            const ratio = this.preset.width / this.preset.height;
            const canvasW = parseInt(this.canvas.style.width) || this.imageWidth;
            const canvasH = parseInt(this.canvas.style.height) || this.imageHeight;

            this.cropH = Math.min(this.imageHeight * 0.8, canvasH * 0.8);
            this.cropW = this.cropH * ratio;

            if (this.cropW > this.imageWidth * 0.9) {
                this.cropW = this.imageWidth * 0.9;
                this.cropH = this.cropW / ratio;
            }

            this.cropX = this.imageX + (this.imageWidth - this.cropW) / 2;
            this.cropY = this.imageY + (this.imageHeight - this.cropH) / 2;
        }

        /**
         * Constrain crop within image bounds
         */
        constrainCrop() {
            this.cropX = Math.max(this.imageX, Math.min(this.cropX, this.imageX + this.imageWidth - this.cropW));
            this.cropY = Math.max(this.imageY, Math.min(this.cropY, this.imageY + this.imageHeight - this.cropH));
        }

        /**
         * Bind canvas mouse and touch events
         */
        bindCanvasEvents() {
            const self = this;
            const canvas = this.canvas;

            canvas.addEventListener('mousedown', function(e) {
                self.onMouseDown(e);
            });

            canvas.addEventListener('touchstart', function(e) {
                self.onTouchStart(e);
            }, { passive: false });

            document.addEventListener('mousemove', this._boundMouseMove);
            document.addEventListener('mouseup', this._boundMouseUp);
            document.addEventListener('touchmove', this._boundTouchMove, { passive: false });
            document.addEventListener('touchend', this._boundTouchEnd);
        }

        /**
         * Get mouse position relative to canvas
         */
        getCanvasPos(e) {
            const rect = this.canvas.getBoundingClientRect();
            return {
                x: e.clientX - rect.left,
                y: e.clientY - rect.top
            };
        }

        /**
         * Detect if position is on a resize handle
         */
        getHandle(x, y) {
            const handleSize = 12;
            const corners = [
                { name: 'tl', x: this.cropX, y: this.cropY },
                { name: 'tr', x: this.cropX + this.cropW, y: this.cropY },
                { name: 'bl', x: this.cropX, y: this.cropY + this.cropH },
                { name: 'br', x: this.cropX + this.cropW, y: this.cropY + this.cropH }
            ];

            for (let i = 0; i < corners.length; i++) {
                const c = corners[i];
                if (Math.abs(x - c.x) <= handleSize && Math.abs(y - c.y) <= handleSize) {
                    return c.name;
                }
            }
            return null;
        }

        /**
         * Check if position is inside crop area
         */
        isInsideCrop(x, y) {
            return x >= this.cropX && x <= this.cropX + this.cropW
                && y >= this.cropY && y <= this.cropY + this.cropH;
        }

        /**
         * Mouse event handlers
         */
        onMouseDown(e) {
            e.preventDefault();
            const pos = this.getCanvasPos(e);

            const handle = this.getHandle(pos.x, pos.y);
            if (handle) {
                this.isResizing = true;
                this.resizeHandle = handle;
                this.dragStartX = pos.x;
                this.dragStartY = pos.y;
                return;
            }

            if (this.isInsideCrop(pos.x, pos.y)) {
                this.isDraggingCrop = true;
                this.dragOffsetX = pos.x - this.cropX;
                this.dragOffsetY = pos.y - this.cropY;
            }
        }

        onMouseMove(e) {
            if (!this.isDraggingCrop && !this.isResizing) {
                // Update cursor
                if (this.canvas) {
                    const pos = this.getCanvasPos(e);
                    const handle = this.getHandle(pos.x, pos.y);
                    if (handle) {
                        this.canvas.style.cursor = handle === 'tl' || handle === 'br' ? 'nwse-resize' : 'nesw-resize';
                    } else if (this.isInsideCrop(pos.x, pos.y)) {
                        this.canvas.style.cursor = 'move';
                    } else {
                        this.canvas.style.cursor = 'default';
                    }
                }
                return;
            }

            e.preventDefault();
            const pos = this.getCanvasPos(e);

            if (this.isDraggingCrop) {
                this.cropX = pos.x - this.dragOffsetX;
                this.cropY = pos.y - this.dragOffsetY;
                this.constrainCrop();
                this.render();
            } else if (this.isResizing) {
                this.handleResize(pos);
                this.render();
            }
        }

        onMouseUp() {
            this.isDraggingCrop = false;
            this.isResizing = false;
            this.resizeHandle = null;
        }

        /**
         * Touch event handlers
         */
        onTouchStart(e) {
            if (e.touches.length === 2) {
                // Pinch to zoom
                e.preventDefault();
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                this.pinchStartDist = Math.sqrt(dx * dx + dy * dy);
                this.pinchStartZoom = this.zoom;
                return;
            }

            if (e.touches.length === 1) {
                const touch = e.touches[0];
                const fakeEvent = { clientX: touch.clientX, clientY: touch.clientY, preventDefault: function() {} };
                this.onMouseDown(fakeEvent);
            }
        }

        onTouchMove(e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                const dist = Math.sqrt(dx * dx + dy * dy);
                const newZoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, this.pinchStartZoom * (dist / this.pinchStartDist)));

                this.zoom = newZoom;
                const slider = this.modal.querySelector('.cropper-zoom-slider');
                if (slider) slider.value = newZoom;
                const label = this.modal.querySelector('.cropper-zoom-value');
                if (label) label.textContent = newZoom.toFixed(1) + 'x';

                this.initCanvas();
                this.constrainCrop();
                this.render();
                return;
            }

            if (e.touches.length === 1 && (this.isDraggingCrop || this.isResizing)) {
                e.preventDefault();
                const touch = e.touches[0];
                const fakeEvent = { clientX: touch.clientX, clientY: touch.clientY, preventDefault: function() {} };
                this.onMouseMove(fakeEvent);
            }
        }

        onTouchEnd() {
            this.onMouseUp();
        }

        /**
         * Handle crop area resize
         */
        handleResize(pos) {
            const ratio = this.preset.width / this.preset.height;
            let newW, newH;

            switch (this.resizeHandle) {
                case 'br':
                    newW = pos.x - this.cropX;
                    newH = newW / ratio;
                    if (newW > 20 && newH > 20) {
                        this.cropW = newW;
                        this.cropH = newH;
                    }
                    break;
                case 'bl':
                    newW = (this.cropX + this.cropW) - pos.x;
                    newH = newW / ratio;
                    if (newW > 20 && newH > 20) {
                        this.cropX = pos.x;
                        this.cropW = newW;
                        this.cropH = newH;
                    }
                    break;
                case 'tr':
                    newW = pos.x - this.cropX;
                    newH = newW / ratio;
                    if (newW > 20 && newH > 20) {
                        this.cropY = this.cropY + this.cropH - newH;
                        this.cropW = newW;
                        this.cropH = newH;
                    }
                    break;
                case 'tl':
                    newW = (this.cropX + this.cropW) - pos.x;
                    newH = newW / ratio;
                    if (newW > 20 && newH > 20) {
                        this.cropX = pos.x;
                        this.cropY = this.cropY + this.cropH - newH;
                        this.cropW = newW;
                        this.cropH = newH;
                    }
                    break;
            }

            this.constrainCrop();
        }

        /**
         * Render canvas: image + crop overlay + handles
         */
        render() {
            const ctx = this.ctx;
            const w = parseInt(this.canvas.style.width);
            const h = parseInt(this.canvas.style.height);

            requestAnimationFrame(function() {
                ctx.clearRect(0, 0, w, h);

                // Draw image
                ctx.drawImage(this.image, this.imageX, this.imageY, this.imageWidth, this.imageHeight);

                // Dark overlay outside crop
                ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
                ctx.fillRect(0, 0, w, h);

                // Clear crop area (show image)
                ctx.clearRect(this.cropX, this.cropY, this.cropW, this.cropH);
                ctx.drawImage(this.image, this.imageX, this.imageY, this.imageWidth, this.imageHeight);

                // Re-darken everything outside crop
                ctx.save();
                ctx.beginPath();
                ctx.rect(0, 0, w, h);
                ctx.rect(this.cropX, this.cropY, this.cropW, this.cropH);
                ctx.clip('evenodd');
                ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
                ctx.fillRect(0, 0, w, h);
                ctx.restore();

                // Crop border
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 2;
                ctx.strokeRect(this.cropX, this.cropY, this.cropW, this.cropH);

                // Rule of thirds lines
                ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
                ctx.lineWidth = 1;
                for (let i = 1; i <= 2; i++) {
                    const xLine = this.cropX + (this.cropW / 3) * i;
                    const yLine = this.cropY + (this.cropH / 3) * i;
                    ctx.beginPath();
                    ctx.moveTo(xLine, this.cropY);
                    ctx.lineTo(xLine, this.cropY + this.cropH);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(this.cropX, yLine);
                    ctx.lineTo(this.cropX + this.cropW, yLine);
                    ctx.stroke();
                }

                // Corner handles
                this.drawHandles(ctx);

                // Update preview
                this.renderPreview();
            }.bind(this));
        }

        drawHandles(ctx) {
            const size = 10;
            const corners = [
                { x: this.cropX, y: this.cropY },
                { x: this.cropX + this.cropW, y: this.cropY },
                { x: this.cropX, y: this.cropY + this.cropH },
                { x: this.cropX + this.cropW, y: this.cropY + this.cropH }
            ];

            ctx.fillStyle = '#ffffff';
            corners.forEach(function(c) {
                ctx.fillRect(c.x - size / 2, c.y - size / 2, size, size);
            });
        }

        /**
         * Render preview panel
         */
        renderPreview() {
            const pCtx = this.previewCtx;
            const pw = this.preset.width;
            const ph = this.preset.height;

            pCtx.clearRect(0, 0, pw, ph);

            // Map crop area back to original image coordinates
            const scaleX = this.naturalWidth / this.imageWidth;
            const scaleY = this.naturalHeight / this.imageHeight;

            const srcX = (this.cropX - this.imageX) * scaleX;
            const srcY = (this.cropY - this.imageY) * scaleY;
            const srcW = this.cropW * scaleX;
            const srcH = this.cropH * scaleY;

            pCtx.drawImage(this.image, srcX, srcY, srcW, srcH, 0, 0, pw, ph);
        }

        /**
         * Apply crop and upload
         */
        applyCrop() {
            const self = this;
            const outputCanvas = document.createElement('canvas');
            const dpr = window.devicePixelRatio || 1;
            outputCanvas.width = this.preset.width * dpr;
            outputCanvas.height = this.preset.height * dpr;

            const outCtx = outputCanvas.getContext('2d');
            outCtx.scale(dpr, dpr);

            const scaleX = this.naturalWidth / this.imageWidth;
            const scaleY = this.naturalHeight / this.imageHeight;

            const srcX = (this.cropX - this.imageX) * scaleX;
            const srcY = (this.cropY - this.imageY) * scaleY;
            const srcW = this.cropW * scaleX;
            const srcH = this.cropH * scaleY;

            outCtx.drawImage(this.image, srcX, srcY, srcW, srcH, 0, 0, this.preset.width, this.preset.height);

            outputCanvas.toBlob(function(blob) {
                if (!blob) {
                    console.error('Failed to create blob from canvas');
                    return;
                }

                if (typeof self.options.onCrop === 'function') {
                    self.options.onCrop(blob);
                    self.close();
                    return;
                }

                self.uploadCroppedImage(blob);
            }, 'image/jpeg', 0.9);
        }

        /**
         * Upload cropped image via AJAX
         */
        uploadCroppedImage(blob) {
            const self = this;
            const applyBtn = this.modal.querySelector('.cropper-btn-apply');
            if (applyBtn) {
                applyBtn.disabled = true;
                applyBtn.textContent = 'Uploading...';
            }

            const formData = new FormData();
            formData.append('action', 'starter_upload_thumbnail');
            formData.append('nonce', nonce);
            formData.append('thumbnail', blob, 'cropped-thumbnail.jpg');
            formData.append('preset', this.options.preset);

            if (this.options.targetInput) {
                const input = document.querySelector(this.options.targetInput);
                if (input && input.value) {
                    formData.append('post_id', input.value);
                }
            }

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Upload failed');
                return response.json();
            })
            .then(function(result) {
                if (result.success) {
                    if (self.options.targetInput) {
                        const input = document.querySelector(self.options.targetInput);
                        if (input) input.value = result.data.attachmentId || result.data.url || '';
                    }

                    const event = new CustomEvent('starterCropComplete', {
                        detail: result.data
                    });
                    document.dispatchEvent(event);

                    self.close();
                } else {
                    console.error('Crop upload failed:', result.data);
                    if (applyBtn) {
                        applyBtn.disabled = false;
                        applyBtn.textContent = 'Apply Crop';
                    }
                }
            })
            .catch(function(error) {
                console.error('Crop upload error:', error);
                if (applyBtn) {
                    applyBtn.disabled = false;
                    applyBtn.textContent = 'Apply Crop';
                }
            });
        }

        /**
         * Close and clean up modal
         */
        close() {
            document.removeEventListener('mousemove', this._boundMouseMove);
            document.removeEventListener('mouseup', this._boundMouseUp);
            document.removeEventListener('touchmove', this._boundTouchMove);
            document.removeEventListener('touchend', this._boundTouchEnd);

            if (this.modal) {
                this.modal.remove();
                this.modal = null;
            }

            document.body.style.overflow = '';
        }
    }

    /**
     * Auto-initialize on thumbnail file inputs
     */
    function initThumbnailInputs() {
        const inputs = document.querySelectorAll('input[type="file"][data-cropper]');

        inputs.forEach(function(input) {
            input.addEventListener('change', function() {
                const file = this.files[0];
                if (!file || !file.type.startsWith('image/')) return;

                const preset = this.getAttribute('data-cropper-preset') || 'manga-cover';
                const targetInput = this.getAttribute('data-cropper-target') || null;

                const cropper = new StarterCropper({
                    preset: preset,
                    targetInput: targetInput
                });

                cropper.open(file);
            });
        });
    }

    // Expose class globally for programmatic use
    window.StarterCropper = StarterCropper;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initThumbnailInputs);
    } else {
        initThumbnailInputs();
    }
})();

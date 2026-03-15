(function() {
    'use strict';

    const data = window.starterData || {};
    const ajaxUrl = data.ajaxUrl || '';
    const nonce = data.nonce || '';

    const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/zip', 'application/x-zip-compressed', 'application/pdf'];
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'pdf'];
    const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB
    const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
    const CHUNK_THRESHOLD = 2 * 1024 * 1024; // Files >2MB use chunked upload

    let uploadQueue = [];
    let isUploading = false;
    let imageList = [];
    let dragSrcIndex = null;

    /**
     * Initialize upload zones
     */
    function initUploadZones() {
        const zones = document.querySelectorAll('.upload-zone');
        zones.forEach(initZone);

        const urlModeToggles = document.querySelectorAll('.url-mode-toggle');
        urlModeToggles.forEach(initUrlMode);
    }

    function initZone(zone) {
        const fileInput = zone.querySelector('.upload-file-input');
        const previewGrid = zone.closest('.upload-wrapper').querySelector('.upload-preview-grid');
        const overallProgress = zone.closest('.upload-wrapper').querySelector('.upload-overall-progress');

        // Drag and drop events
        zone.addEventListener('dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('drag-over');
        });

        zone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('drag-over');
            }
        });

        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('drag-over');

            const files = Array.from(e.dataTransfer.files);
            handleFiles(files, previewGrid, overallProgress);
        });

        zone.addEventListener('click', function() {
            if (fileInput) fileInput.click();
        });

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const files = Array.from(this.files);
                handleFiles(files, previewGrid, overallProgress);
                this.value = '';
            });
        }
    }

    /**
     * Validate a single file
     */
    function validateFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();

        if (!ALLOWED_EXTENSIONS.includes(ext) && !ALLOWED_TYPES.includes(file.type)) {
            return { valid: false, error: 'Invalid file type: ' + file.name };
        }

        if (file.size > MAX_FILE_SIZE) {
            return { valid: false, error: 'File too large: ' + file.name + ' (max 50MB)' };
        }

        return { valid: true };
    }

    /**
     * Handle selected/dropped files
     */
    function handleFiles(files, previewGrid, overallProgress) {
        const validFiles = [];

        files.forEach(function(file) {
            const validation = validateFile(file);
            if (validation.valid) {
                validFiles.push(file);
            } else {
                showUploadError(validation.error);
            }
        });

        if (validFiles.length === 0) return;

        validFiles.forEach(function(file) {
            const index = imageList.length;
            const entry = {
                file: file,
                id: 'upload-' + Date.now() + '-' + index,
                status: 'pending',
                progress: 0,
                uploadedChunks: [],
                uploadId: null
            };
            imageList.push(entry);
            addPreviewItem(entry, previewGrid);
            uploadQueue.push({ entry: entry, previewGrid: previewGrid, overallProgress: overallProgress });
        });

        processQueue(overallProgress);
    }

    /**
     * Add preview item to grid
     */
    function addPreviewItem(entry, grid) {
        if (!grid) return;

        const item = document.createElement('div');
        item.className = 'upload-preview-item';
        item.setAttribute('data-id', entry.id);
        item.setAttribute('draggable', 'true');

        const isImage = entry.file.type.startsWith('image/');
        const isZip = entry.file.type.includes('zip');

        let thumbContent = '';
        if (isImage) {
            thumbContent = '<img src="" alt="' + escapeAttr(entry.file.name) + '" class="preview-thumb">';
        } else if (isZip) {
            thumbContent = '<div class="preview-icon">ZIP</div>';
        } else {
            thumbContent = '<div class="preview-icon">PDF</div>';
        }

        item.innerHTML = thumbContent
            + '<div class="preview-name">' + escapeHtml(entry.file.name) + '</div>'
            + '<div class="preview-progress"><div class="preview-progress-bar" style="width:0%"></div></div>'
            + '<button class="preview-remove" data-id="' + entry.id + '" title="Remove">&times;</button>'
            + '<div class="preview-status"></div>';

        grid.appendChild(item);

        // Generate image thumbnail
        if (isImage) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = item.querySelector('.preview-thumb');
                if (img) img.src = e.target.result;
            };
            reader.readAsDataURL(entry.file);
        }

        // Remove button
        item.querySelector('.preview-remove').addEventListener('click', function(e) {
            e.stopPropagation();
            removeImage(entry.id, grid);
        });

        // Drag to reorder
        item.addEventListener('dragstart', function(e) {
            dragSrcIndex = getItemIndex(entry.id);
            e.dataTransfer.effectAllowed = 'move';
            item.classList.add('dragging');
        });

        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            item.classList.add('drag-target');
        });

        item.addEventListener('dragleave', function() {
            item.classList.remove('drag-target');
        });

        item.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            item.classList.remove('drag-target');

            const targetIndex = getItemIndex(entry.id);
            if (dragSrcIndex !== null && dragSrcIndex !== targetIndex) {
                reorderImages(dragSrcIndex, targetIndex, grid);
            }
            dragSrcIndex = null;
        });

        item.addEventListener('dragend', function() {
            item.classList.remove('dragging');
            grid.querySelectorAll('.drag-target').forEach(function(el) {
                el.classList.remove('drag-target');
            });
        });
    }

    function getItemIndex(id) {
        return imageList.findIndex(function(entry) { return entry.id === id; });
    }

    function reorderImages(fromIndex, toIndex, grid) {
        const moved = imageList.splice(fromIndex, 1)[0];
        imageList.splice(toIndex, 0, moved);

        // Re-render order in DOM
        const items = Array.from(grid.querySelectorAll('.upload-preview-item'));
        const sortedItems = imageList.map(function(entry) {
            return items.find(function(el) { return el.getAttribute('data-id') === entry.id; });
        }).filter(Boolean);

        sortedItems.forEach(function(el) {
            grid.appendChild(el);
        });
    }

    function removeImage(id, grid) {
        const index = getItemIndex(id);
        if (index >= 0) {
            imageList.splice(index, 1);
        }
        uploadQueue = uploadQueue.filter(function(q) { return q.entry.id !== id; });

        const el = grid.querySelector('[data-id="' + id + '"]');
        if (el) {
            el.style.opacity = '0';
            el.style.transform = 'scale(0.8)';
            setTimeout(function() { el.remove(); }, 200);
        }
    }

    /**
     * Upload queue processor
     */
    function processQueue(overallProgress) {
        if (isUploading || uploadQueue.length === 0) return;
        isUploading = true;

        const next = uploadQueue.shift();
        uploadFile(next.entry, next.previewGrid, next.overallProgress)
            .then(function() {
                isUploading = false;
                updateOverallProgress(overallProgress);
                processQueue(overallProgress);
            })
            .catch(function() {
                isUploading = false;
                updateOverallProgress(overallProgress);
                processQueue(overallProgress);
            });
    }

    /**
     * Upload single file (chunked or standard)
     */
    function uploadFile(entry, previewGrid, overallProgress) {
        const item = previewGrid ? previewGrid.querySelector('[data-id="' + entry.id + '"]') : null;
        const statusEl = item ? item.querySelector('.preview-status') : null;

        const ext = entry.file.name.split('.').pop().toLowerCase();
        if (ext === 'zip') {
            if (statusEl) statusEl.textContent = 'Processing ZIP...';
        }

        if (entry.file.size > CHUNK_THRESHOLD) {
            return chunkedUpload(entry, item);
        }
        return standardUpload(entry, item);
    }

    function standardUpload(entry, item) {
        return new Promise(function(resolve, reject) {
            const formData = new FormData();
            formData.append('action', 'starter_upload_image');
            formData.append('nonce', nonce);
            formData.append('file', entry.file);

            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    entry.progress = percent;
                    updateItemProgress(item, percent);
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const result = JSON.parse(xhr.responseText);
                        if (result.success) {
                            entry.status = 'complete';
                            entry.progress = 100;
                            updateItemStatus(item, 'complete');
                            resolve(result);
                        } else {
                            entry.status = 'error';
                            updateItemStatus(item, 'error', result.data || 'Upload failed');
                            reject(new Error(result.data || 'Upload failed'));
                        }
                    } catch (e) {
                        entry.status = 'error';
                        updateItemStatus(item, 'error', 'Invalid response');
                        reject(e);
                    }
                } else {
                    entry.status = 'error';
                    updateItemStatus(item, 'error', 'Upload failed (HTTP ' + xhr.status + ')');
                    reject(new Error('HTTP ' + xhr.status));
                }
            });

            xhr.addEventListener('error', function() {
                entry.status = 'error';
                updateItemStatus(item, 'error', 'Network error');
                reject(new Error('Network error'));
            });

            xhr.open('POST', ajaxUrl, true);
            xhr.withCredentials = true;
            xhr.send(formData);
        });
    }

    /**
     * Chunked upload for large files
     */
    function chunkedUpload(entry, item) {
        const file = entry.file;
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const uploadId = entry.uploadId || ('chunk-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9));
        entry.uploadId = uploadId;

        let currentChunk = entry.uploadedChunks.length;

        function uploadNextChunk() {
            if (currentChunk >= totalChunks) {
                entry.status = 'complete';
                entry.progress = 100;
                updateItemProgress(item, 100);
                updateItemStatus(item, 'complete');
                return Promise.resolve();
            }

            const start = currentChunk * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);

            const formData = new FormData();
            formData.append('action', 'starter_upload_chunk');
            formData.append('nonce', nonce);
            formData.append('chunk', chunk);
            formData.append('chunk_index', currentChunk);
            formData.append('total_chunks', totalChunks);
            formData.append('upload_id', uploadId);
            formData.append('filename', file.name);

            return sendChunk(formData, entry, item, currentChunk, totalChunks)
                .then(function() {
                    entry.uploadedChunks.push(currentChunk);
                    currentChunk++;
                    return uploadNextChunk();
                })
                .catch(function(error) {
                    return retryChunk(formData, entry, item, currentChunk, totalChunks, 3);
                });
        }

        return uploadNextChunk();
    }

    function sendChunk(formData, entry, item, chunkIndex, totalChunks) {
        return new Promise(function(resolve, reject) {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const chunkProgress = e.loaded / e.total;
                    const overallPercent = Math.round(((chunkIndex + chunkProgress) / totalChunks) * 100);
                    entry.progress = overallPercent;
                    updateItemProgress(item, overallPercent);
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const result = JSON.parse(xhr.responseText);
                        if (result.success) {
                            resolve(result);
                        } else {
                            reject(new Error(result.data || 'Chunk upload failed'));
                        }
                    } catch (e) {
                        reject(e);
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            });

            xhr.addEventListener('error', function() {
                reject(new Error('Network error'));
            });

            xhr.open('POST', ajaxUrl, true);
            xhr.withCredentials = true;
            xhr.send(formData);
        });
    }

    /**
     * Retry failed chunk with exponential backoff
     */
    function retryChunk(formData, entry, item, chunkIndex, totalChunks, retriesLeft) {
        if (retriesLeft <= 0) {
            entry.status = 'error';
            updateItemStatus(item, 'error', 'Upload failed after retries');
            return Promise.reject(new Error('Max retries reached'));
        }

        const delay = Math.pow(2, 3 - retriesLeft) * 1000;

        return new Promise(function(resolve) {
            setTimeout(resolve, delay);
        }).then(function() {
            return sendChunk(formData, entry, item, chunkIndex, totalChunks)
                .catch(function() {
                    return retryChunk(formData, entry, item, chunkIndex, totalChunks, retriesLeft - 1);
                });
        });
    }

    /**
     * Progress and status updates
     */
    function updateItemProgress(item, percent) {
        if (!item) return;
        const bar = item.querySelector('.preview-progress-bar');
        if (bar) bar.style.width = percent + '%';
    }

    function updateItemStatus(item, status, message) {
        if (!item) return;
        const statusEl = item.querySelector('.preview-status');
        if (statusEl) {
            statusEl.textContent = message || (status === 'complete' ? 'Uploaded' : 'Error');
            statusEl.className = 'preview-status status-' + status;
        }
        item.classList.add('upload-' + status);
    }

    function updateOverallProgress(overallEl) {
        if (!overallEl) return;

        const total = imageList.length;
        if (total === 0) {
            overallEl.style.display = 'none';
            return;
        }

        const totalProgress = imageList.reduce(function(sum, entry) {
            return sum + (entry.progress || 0);
        }, 0);

        const percent = Math.round(totalProgress / total);
        overallEl.style.display = '';

        const bar = overallEl.querySelector('.overall-progress-bar');
        if (bar) bar.style.width = percent + '%';

        const text = overallEl.querySelector('.overall-progress-text');
        if (text) text.textContent = percent + '% complete';
    }

    /**
     * External URL paste mode
     */
    function initUrlMode(toggle) {
        toggle.addEventListener('click', function() {
            const wrapper = this.closest('.upload-wrapper');
            if (!wrapper) return;

            const zone = wrapper.querySelector('.upload-zone');
            const urlPanel = wrapper.querySelector('.upload-url-panel');

            if (zone) zone.classList.toggle('hidden');
            if (urlPanel) urlPanel.classList.toggle('hidden');

            this.textContent = zone && zone.classList.contains('hidden') ? 'Switch to File Upload' : 'Switch to URL Mode';
        });

        const urlTextarea = document.querySelector('.upload-url-textarea');
        const urlSubmit = document.querySelector('.upload-url-submit');

        if (urlTextarea) {
            urlTextarea.addEventListener('input', function() {
                validateUrls(this);
            });
        }

        if (urlSubmit) {
            urlSubmit.addEventListener('click', function() {
                submitUrls(urlTextarea);
            });
        }
    }

    function validateUrls(textarea) {
        const lines = textarea.value.split('\n').filter(function(l) { return l.trim(); });
        const feedback = textarea.closest('.upload-url-panel').querySelector('.url-validation-feedback');
        if (!feedback) return;

        const urlPattern = /^https?:\/\/.+\..+/i;
        const invalid = lines.filter(function(line) { return !urlPattern.test(line.trim()); });

        if (invalid.length > 0 && lines.length > 0) {
            feedback.textContent = invalid.length + ' invalid URL(s) detected';
            feedback.className = 'url-validation-feedback invalid';
        } else {
            feedback.textContent = lines.length > 0 ? lines.length + ' URL(s) ready' : '';
            feedback.className = 'url-validation-feedback valid';
        }
    }

    function submitUrls(textarea) {
        if (!textarea) return;

        const urlPattern = /^https?:\/\/.+\..+/i;
        const urls = textarea.value.split('\n')
            .map(function(l) { return l.trim(); })
            .filter(function(l) { return urlPattern.test(l); });

        if (urls.length === 0) {
            showUploadError('No valid URLs found');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'starter_upload_urls');
        formData.append('nonce', nonce);
        formData.append('urls', JSON.stringify(urls));

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('URL submission failed');
            return response.json();
        })
        .then(function(result) {
            if (result.success) {
                textarea.value = '';
                showUploadSuccess('URLs submitted successfully');
            } else {
                showUploadError(result.data || 'Failed to process URLs');
            }
        })
        .catch(function(error) {
            console.error('URL submit error:', error);
            showUploadError('Failed to submit URLs. Please try again.');
        });
    }

    /**
     * Utility functions
     */
    function showUploadError(message) {
        const container = document.querySelector('.upload-messages');
        if (container) {
            const msg = document.createElement('div');
            msg.className = 'upload-message upload-error';
            msg.textContent = message;
            container.appendChild(msg);
            setTimeout(function() { msg.remove(); }, 5000);
        } else {
            console.error('Upload error:', message);
        }
    }

    function showUploadSuccess(message) {
        const container = document.querySelector('.upload-messages');
        if (container) {
            const msg = document.createElement('div');
            msg.className = 'upload-message upload-success';
            msg.textContent = message;
            container.appendChild(msg);
            setTimeout(function() { msg.remove(); }, 5000);
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initUploadZones();
    }
})();

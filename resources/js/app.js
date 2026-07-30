import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

const rupiah = (value) => `Rp ${Number(value || 0).toLocaleString('id-ID')}`;
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
const parseProducts = (value) => {
    try {
        const parsed = JSON.parse(value || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.dataset.originalText = button.textContent;
                button.textContent = 'Memproses...';
            }
        });
    });

    const toast = document.querySelector('[data-toast]');
    if (toast) {
        setTimeout(() => toast.remove(), 4500);
    }

    document.querySelectorAll('[data-print-receipt]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    const root = document.querySelector('[data-pos-root]');
    const barcodeInput = document.getElementById('barcodeInput');
    const scannerModal = document.getElementById('barcodeScanModal');
    const scannerInput = document.getElementById('scannerCodeInput');
    const scannerVideo = document.getElementById('barcodeVideo');
    const scannerStatus = scannerModal?.querySelector('[data-scan-status]');
    const cameraEmpty = scannerModal?.querySelector('[data-camera-empty]');
    const createFromScanLink = scannerModal?.querySelector('[data-create-from-scan]');
    const scannerProducts = parseProducts(scannerModal?.dataset.products || root?.dataset.products || '[]');

    let scannerTarget = { mode: 'pos' };
    let zxingControls = null;
    let addProductFromScanner = null;

    const setScannerStatus = (message) => {
        if (scannerStatus) {
            scannerStatus.textContent = message;
        }
    };

    const findProductByBarcode = (code, source = scannerProducts) => {
        const normalized = code.trim().toLowerCase();
        const variants = new Set([normalized]);
        if (/^\d{12}$/.test(normalized)) {
            variants.add(`0${normalized}`);
        }
        if (/^0\d{12}$/.test(normalized)) {
            variants.add(normalized.slice(1));
        }

        return source.find((product) => variants.has(String(product.barcode || '').trim().toLowerCase()));
    };

    const stopCamera = () => {
        zxingControls?.stop();
        zxingControls = null;
        if (scannerVideo) {
            scannerVideo.pause();
            scannerVideo.srcObject = null;
            scannerVideo.hidden = true;
        }
        if (cameraEmpty) {
            cameraEmpty.hidden = false;
        }
    };

    const closeScanner = () => {
        stopCamera();
        if (scannerModal) {
            scannerModal.hidden = true;
        }
    };

    const applyScan = (rawCode) => {
        const code = rawCode.trim();
        if (!code) {
            setScannerStatus('Barcode kosong.');
            return false;
        }
        if (scannerInput) {
            scannerInput.value = code;
        }

        if (scannerTarget.mode === 'barcode-field') {
            const targetInput = scannerTarget.targetInput ? document.querySelector(scannerTarget.targetInput) : null;
            if (!targetInput) {
                setScannerStatus('Field barcode tidak ditemukan.');
                return false;
            }
            targetInput.value = code;
            targetInput.dispatchEvent(new Event('input', { bubbles: true }));

            const skuInput = scannerTarget.targetSku ? document.querySelector(scannerTarget.targetSku) : null;
            if (skuInput && !skuInput.value.trim()) {
                skuInput.value = code.slice(0, 60);
                skuInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            const nextInput = scannerTarget.nextFocus ? document.querySelector(scannerTarget.nextFocus) : targetInput;
            closeScanner();
            nextInput?.focus();
            return true;
        }

        const product = findProductByBarcode(code);
        if (!product) {
            setScannerStatus(`Barcode ${code} terbaca, tapi belum terdaftar di produk aktif.`);
            if (createFromScanLink) {
                const createUrl = new URL(createFromScanLink.href, window.location.origin);
                createUrl.searchParams.set('barcode', code);
                createFromScanLink.href = createUrl.toString();
                createFromScanLink.hidden = false;
            }
            return false;
        }

        if (scannerTarget.mode === 'select-product') {
            const targetSelect = scannerTarget.targetSelect ? document.querySelector(scannerTarget.targetSelect) : null;
            if (!targetSelect) {
                setScannerStatus('Pilihan produk tidak ditemukan.');
                return false;
            }
            targetSelect.value = String(product.id);
            targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
            closeScanner();
            targetSelect.focus();
            return true;
        }

        if (addProductFromScanner) {
            addProductFromScanner(product.id);
        } else if (barcodeInput) {
            barcodeInput.value = code;
        }
        closeScanner();
        barcodeInput?.focus();
        return true;
    };

    const startCamera = async () => {
        if (!navigator.mediaDevices?.getUserMedia || !scannerVideo) {
            setScannerStatus('Kamera tidak tersedia di browser ini. Gunakan scanner USB atau input manual.');
            scannerInput?.focus();
            return;
        }

        stopCamera();
        try {
            const constraints = {
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                },
            };

            scannerVideo.hidden = false;
            if (cameraEmpty) {
                cameraEmpty.hidden = true;
            }
            setScannerStatus('Kamera aktif.');

            const hints = new Map();
            hints.set(DecodeHintType.TRY_HARDER, true);
            hints.set(DecodeHintType.POSSIBLE_FORMATS, [
                BarcodeFormat.EAN_13,
                BarcodeFormat.EAN_8,
                BarcodeFormat.UPC_A,
                BarcodeFormat.UPC_E,
                BarcodeFormat.CODE_128,
                BarcodeFormat.CODE_39,
                BarcodeFormat.CODE_93,
                BarcodeFormat.ITF,
                BarcodeFormat.CODABAR,
                BarcodeFormat.QR_CODE,
                BarcodeFormat.DATA_MATRIX,
                BarcodeFormat.PDF_417,
            ]);

            const reader = new BrowserMultiFormatReader(hints);
            zxingControls = await reader.decodeFromConstraints(constraints, scannerVideo, (result, error, controls) => {
                const text = result?.getText?.() || '';
                if (text) {
                    applyScan(text);
                    controls.stop();
                    zxingControls = null;
                }
            });
        } catch {
            stopCamera();
            setScannerStatus('Kamera tidak bisa dibuka. Gunakan scanner USB atau input manual.');
            scannerInput?.focus();
        }
    };

    document.querySelectorAll('[data-open-scanner]').forEach((button) => {
        button.addEventListener('click', () => {
            scannerTarget = {
                mode: button.dataset.scanMode || 'pos',
                targetInput: button.dataset.targetInput,
                targetSelect: button.dataset.targetSelect,
                targetSku: button.dataset.targetSku,
                nextFocus: button.dataset.nextFocus,
            };
            if (scannerInput) {
                scannerInput.value = '';
            }
            if (createFromScanLink) {
                createFromScanLink.hidden = true;
            }
            if (scannerModal) {
                scannerModal.hidden = false;
            }
            setScannerStatus('Membuka kamera...');
            void startCamera();
        });
    });

    document.querySelectorAll('[data-close-scanner]').forEach((button) => {
        button.addEventListener('click', closeScanner);
    });

    scannerModal?.addEventListener('click', (event) => {
        if (event.target === scannerModal) {
            closeScanner();
        }
    });

    scannerInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        applyScan(scannerInput.value);
    });

    document.querySelector('[data-start-camera]')?.addEventListener('click', startCamera);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && scannerModal && !scannerModal.hidden) {
            closeScanner();
            barcodeInput?.focus();
        }
    });

    if (!root) {
        return;
    }

    const products = parseProducts(root.dataset.products || '[]');

    if (!Array.isArray(products)) {
        return;
    }
    const productMap = new Map(products.map((product) => [Number(product.id), product]));
    const cartItems = document.getElementById('cartItems');
    const cartSubtotal = document.getElementById('cartSubtotal');
    const cartTotal = document.getElementById('cartTotal');
    const modalTotal = document.getElementById('modalTotal');
    const paidAmount = document.getElementById('paidAmount');
    const changePreview = document.getElementById('changePreview');
    const discountInput = document.getElementById('discountInput');
    const paymentModal = document.getElementById('paymentModal');
    const checkoutForm = document.getElementById('checkoutForm');
    const checkoutItems = document.getElementById('checkoutItems');
    const idempotencyKey = document.getElementById('idempotencyKey');

    let cart = JSON.parse(localStorage.getItem('warungpos.cart') || '[]');

    const save = () => localStorage.setItem('warungpos.cart', JSON.stringify(cart));
    const subtotal = () => cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discount = () => Math.min(Number(discountInput.value || 0), subtotal());
    const total = () => Math.max(0, subtotal() - discount());

    const render = () => {
        if (cart.length === 0) {
            cartItems.className = 'cart-items empty-state';
            cartItems.textContent = 'Belum ada produk.';
        } else {
            cartItems.className = 'cart-items';
            cartItems.innerHTML = cart.map((item) => `
                <div class="cart-item">
                    <div>
                        <strong>${item.name}</strong>
                        <small>${rupiah(item.price)} x ${item.quantity}</small>
                    </div>
                    <div class="qty-controls">
                        <button type="button" data-qty="${item.id}" data-delta="-1">-</button>
                        <strong>${item.quantity}</strong>
                        <button type="button" data-qty="${item.id}" data-delta="1">+</button>
                        <button type="button" data-remove="${item.id}">&times;</button>
                    </div>
                </div>
            `).join('');
        }

        cartSubtotal.textContent = rupiah(subtotal());
        cartTotal.textContent = rupiah(total());
        modalTotal.textContent = rupiah(total());
        paidAmount.value = Math.max(Number(paidAmount.value || 0), total());
        changePreview.textContent = `Kembalian ${rupiah(Math.max(0, Number(paidAmount.value || 0) - total()))}`;
        save();
    };

    const addProduct = (id) => {
        const product = productMap.get(Number(id));
        if (!product || product.stock <= 0) {
            alert('Produk tidak tersedia.');
            return;
        }

        const existing = cart.find((item) => item.id === product.id);
        if (existing) {
            if (existing.quantity >= product.stock) {
                alert('Stok produk tidak mencukupi.');
                return;
            }
            existing.quantity += 1;
        } else {
            cart.push({ id: product.id, name: product.name, price: product.price, stock: product.stock, quantity: 1 });
        }
        render();
    };

    addProductFromScanner = addProduct;

    root.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-product]');
        if (addButton) {
            addProduct(addButton.dataset.addProduct);
        }

        const qtyButton = event.target.closest('[data-qty]');
        if (qtyButton) {
            const item = cart.find((row) => row.id === Number(qtyButton.dataset.qty));
            if (!item) return;
            item.quantity += Number(qtyButton.dataset.delta);
            if (item.quantity <= 0) {
                cart = cart.filter((row) => row.id !== item.id);
            }
            if (item.quantity > item.stock) {
                item.quantity = item.stock;
                alert('Stok produk tidak mencukupi.');
            }
            render();
        }

        const removeButton = event.target.closest('[data-remove]');
        if (removeButton) {
            cart = cart.filter((item) => item.id !== Number(removeButton.dataset.remove));
            render();
        }

        const clearButton = event.target.closest('[data-clear-cart]');
        if (clearButton && confirm('Kosongkan keranjang?')) {
            cart = [];
            render();
        }

        const chip = event.target.closest('[data-category]');
        if (chip) {
            document.querySelectorAll('[data-category]').forEach((button) => button.classList.remove('active'));
            chip.classList.add('active');
            document.querySelectorAll('[data-add-product]').forEach((card) => {
                const visible = !chip.dataset.category || card.dataset.category === chip.dataset.category;
                card.style.display = visible ? '' : 'none';
            });
        }

        const payButton = event.target.closest('[data-open-payment]');
        if (payButton) {
            if (cart.length === 0) {
                alert('Keranjang masih kosong.');
                return;
            }
            idempotencyKey.value = uuid();
            checkoutItems.value = JSON.stringify(cart.map((item) => ({ product_id: item.id, quantity: item.quantity })));
            paymentModal.hidden = false;
            paidAmount.focus();
            render();
        }
    });

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            paymentModal.hidden = true;
            barcodeInput?.focus();
        });
    });

    barcodeInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const term = barcodeInput.value.trim().toLowerCase();
        const found = products.find((product) => String(product.barcode || '').trim().toLowerCase() === term || product.name.toLowerCase().includes(term));
        if (found) {
            addProduct(found.id);
            barcodeInput.value = '';
        } else {
            alert('Barcode tidak ditemukan.');
        }
    });

    discountInput?.addEventListener('input', render);
    paidAmount?.addEventListener('input', render);

    checkoutForm?.addEventListener('submit', () => {
        checkoutItems.value = JSON.stringify(cart.map((item) => ({ product_id: item.id, quantity: item.quantity })));
        localStorage.removeItem('warungpos.cart');
        cart = [];
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'F2') {
            event.preventDefault();
            barcodeInput?.focus();
        }
        if (event.key === 'F8') {
            event.preventDefault();
            document.querySelector('[data-open-payment]')?.click();
        }
        if (event.key === 'Escape' && paymentModal && !paymentModal.hidden) {
            paymentModal.hidden = true;
            barcodeInput?.focus();
        }
    });

    render();
    barcodeInput?.focus();
});

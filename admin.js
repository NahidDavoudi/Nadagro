document.addEventListener('DOMContentLoaded', () => {
    // Check if logged in
    if (localStorage.getItem('admin_token')) {
        showAdminPanel();
    } else {
        showLoginPage();
    }

    // Event Listeners
    document.getElementById('login-form').addEventListener('submit', handleLogin);
    document.getElementById('logout-btn').addEventListener('click', handleLogout);

    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            if(e.currentTarget.id === 'logout-btn') return;
            
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            e.currentTarget.classList.add('active');
            const page = e.currentTarget.dataset.page;
            loadPage(page);
        });
    });
    
    document.getElementById('product-form').addEventListener('submit', handleProductSave);
});

const API_URL = 'admin_api.php';

// --- Core Functions ---
async function api(action, data = null, method = 'GET') {
    const url = new URL(API_URL, window.location.href);
    url.searchParams.append('action', action);

    const options = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include'
    };

    if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(url.toString(), options);
        if (response.status === 401 || response.status === 403) {
            handleLogout();
            throw new Error("دسترسی غیرمجاز یا نشست منقضی شده.");
        }
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message);
        }
        return result.data;
    } catch (error) {
        alert(`خطا: ${error.message}`);
        throw error;
    }
}

function showLoginPage() {
    document.getElementById('login-page').classList.remove('hidden');
    document.getElementById('admin-panel').classList.add('hidden');
}

function showAdminPanel() {
    document.getElementById('login-page').classList.add('hidden');
    document.getElementById('admin-panel').classList.remove('hidden');
    feather.replace();
    loadPage('dashboard');
}

// --- Page Loading ---
async function loadPage(page) {
    const content = document.getElementById('page-content');
    content.innerHTML = `<div class="text-center p-8">...در حال بارگذاری</div>`;
    try {
        switch (page) {
            case 'dashboard':
                await loadDashboard();
                break;
            case 'products':
                await loadProducts();
                break;
            case 'orders':
                await loadOrders();
                break;
            case 'users':
                await loadUsers();
                break;
        }
    } catch(e) {
        content.innerHTML = `<div class="text-center p-8 text-red-500">خطا در بارگذاری اطلاعات.</div>`;
    }
     feather.replace();
}

// --- Auth Handling ---
async function handleLogin(e) {
    e.preventDefault();
    const username = e.target.username.value;
    const password = e.target.password.value;
    try {
        const result = await api('login', { username, password }, 'POST');
        localStorage.setItem('admin_token', result.name); // Simple token
        showAdminPanel();
    } catch (error) {
        // Handled by api()
    }
}

function handleLogout() {
    localStorage.removeItem('admin_token');
    api('logout').finally(showLoginPage);
}

// --- Page Content Loaders ---
async function loadDashboard() {
    const stats = await api('dashboard_stats');
    const content = `
        <h2 class="text-2xl font-bold mb-6">داشبورد</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="stat-card">
                <p class="text-gray-500">مجموع کاربران</p>
                <p class="text-3xl font-bold">${stats.users_count}</p>
            </div>
            <div class="stat-card">
                <p class="text-gray-500">مجموع محصولات</p>
                <p class="text-3xl font-bold">${stats.products_count}</p>
            </div>
            <div class="stat-card">
                <p class="text-gray-500">مجموع سفارشات</p>
                <p class="text-3xl font-bold">${stats.orders_count}</p>
            </div>
            <div class="stat-card">
                <p class="text-gray-500">درآمد کل (تومان)</p>
                <p class="text-3xl font-bold">${Number(stats.total_revenue).toLocaleString('fa-IR')}</p>
            </div>
        </div>
    `;
    document.getElementById('page-content').innerHTML = content;
}

async function loadProducts() {
    const products = await api('get_products');
    let content = `
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">مدیریت محصولات</h2>
            <button class="btn-primary" onclick="openProductModal()">افزودن محصول</button>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>نام</th><th>قیمت</th><th>موجودی</th><th>اقدامات</th>
                    </tr>
                </thead>
                <tbody>
                    ${products.map(p => `
                        <tr>
                            <td>${p.id}</td>
                            <td class="font-semibold text-gray-800">${p.name}</td>
                            <td>${Number(p.price).toLocaleString('fa-IR')}</td>
                            <td>${p.stock}</td>
                            <td class="space-x-2 space-x-reverse">
                                <button onclick="openProductModal(${p.id})">ویرایش</button>
                                <button class="text-red-500" onclick="deleteProduct(${p.id})">حذف</button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
    document.getElementById('page-content').innerHTML = content;
}

async function loadOrders() {
    const orders = await api('get_orders');
    let content = `
        <h2 class="text-2xl font-bold mb-6">مدیریت سفارشات</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>کاربر</th><th>مبلغ کل</th><th>وضعیت</th><th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    ${orders.map(o => `
                        <tr>
                            <td>${o.id}</td>
                            <td class="font-semibold text-gray-800">${o.user_name}</td>
                            <td>${Number(o.total_amount).toLocaleString('fa-IR')}</td>
                            <td>
                                <select onchange="updateOrderStatus(${o.id}, this.value)" class="border rounded-md p-1">
                                    <option value="در حال پردازش" ${o.status === 'در حال پردازش' ? 'selected' : ''}>در حال پردازش</option>
                                    <option value="ارسال شده" ${o.status === 'ارسال شده' ? 'selected' : ''}>ارسال شده</option>
                                    <option value="تحویل شده" ${o.status === 'تحویل شده' ? 'selected' : ''}>تحویل شده</option>
                                    <option value="لغو شده" ${o.status === 'لغو شده' ? 'selected' : ''}>لغو شده</option>
                                </select>
                            </td>
                            <td>${new Date(o.created_at).toLocaleDateString('fa-IR')}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
    document.getElementById('page-content').innerHTML = content;
}

async function loadUsers() {
    const users = await api('get_users');
    let content = `
        <h2 class="text-2xl font-bold mb-6">مدیریت کاربران</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>نام</th><th>ایمیل</th><th>تلفن</th><th>ادمین</th>
                    </tr>
                </thead>
                <tbody>
                    ${users.map(u => `
                        <tr>
                            <td>${u.id}</td>
                            <td class="font-semibold text-gray-800">${u.name}</td>
                            <td>${u.email}</td>
                            <td>${u.phone}</td>
                            <td>${u.is_admin ? 'بله' : 'خیر'}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
     document.getElementById('page-content').innerHTML = content;
}


// --- Product Modal & Actions ---
const modal = document.getElementById('product-modal');

function closeModal() {
    modal.classList.add('hidden');
}

async function openProductModal(productId = null) {
    const form = document.getElementById('product-form');
    form.reset();
    document.getElementById('productId').value = '';
    
    if (productId) {
        document.getElementById('modal-title').innerText = 'ویرایش محصول';
        const products = await api('get_products'); // In a real app, you might fetch a single product
        const product = products.find(p => p.id === productId);
        if (product) {
            document.getElementById('productId').value = product.id;
            document.getElementById('productName').value = product.name;
            document.getElementById('productPrice').value = product.price;
            document.getElementById('productStock').value = product.stock;
            document.getElementById('productImage').value = product.image || '';
            document.getElementById('productDescription').value = product.description || '';
        }
    } else {
        document.getElementById('modal-title').innerText = 'افزودن محصول جدید';
    }
    modal.classList.remove('hidden');
}

async function handleProductSave(e) {
    e.preventDefault();
    const product = {
        id: document.getElementById('productId').value,
        name: document.getElementById('productName').value,
        price: document.getElementById('productPrice').value,
        stock: document.getElementById('productStock').value,
        image: document.getElementById('productImage').value,
        description: document.getElementById('productDescription').value,
    };
    
    try {
        if (product.id) {
            await api('update_product', product, 'PUT');
        } else {
            await api('add_product', product, 'POST');
        }
        closeModal();
        loadPage('products');
    } catch(err) {
        // handled by api()
    }
}

async function deleteProduct(productId) {
    if (confirm('آیا از حذف این محصول اطمینان دارید؟')) {
        try {
            await api('delete_product', { id: productId }, 'DELETE');
            loadPage('products');
        } catch (error) {
            // handled by api()
        }
    }
}

// --- Order Actions ---
async function updateOrderStatus(orderId, status) {
    try {
        await api('update_order_status', { id: orderId, status: status }, 'PUT');
        alert('وضعیت سفارش به روز شد.');
        // Optionally, you can visually confirm the change without a full reload
    } catch (error) {
        // If there's an error, reload to show the original status
        loadPage('orders');
    }
}

// Activate admin tabs
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.admin-tabs button');
    const tabContents = document.querySelectorAll('.admin-tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to selected tab
            this.classList.add('active');
            
            // Show appropriate content
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });
    
    // Confirm before deletion
    const deleteButtons = document.querySelectorAll('.delete-btn');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete?')) {
                e.preventDefault();
            }
        });
    });
    
    // Update total price in cart
    const quantityInputs = document.querySelectorAll('.cart input[type="number"]');
    quantityInputs.forEach(input => {
        input.addEventListener('change', function() {
            const row = this.closest('tr');
            const price = parseFloat(row.querySelector('.price').textContent);
            const quantity = parseInt(this.value);
            const subtotal = price * quantity;
            row.querySelector('.subtotal').textContent = subtotal.toFixed(2) + ' دينار';
            
            // Update total
            let total = 0;
            document.querySelectorAll('.cart tbody tr').forEach(row => {
                total += parseFloat(row.querySelector('.subtotal').textContent);
            });
            document.querySelector('.cart tfoot td:last-child').textContent = total.toFixed(2) + ' ريال';
        });
    });
});

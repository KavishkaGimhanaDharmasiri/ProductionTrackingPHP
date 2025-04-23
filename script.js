document.addEventListener('DOMContentLoaded', function() {
    const forms = document.getElementsByTagName('form');
    
    for (let form of forms) {
        form.addEventListener('submit', function(e) {
            const inputs = form.getElementsByTagName('input');
            for (let input of inputs) {
                if (input.type === 'number' && input.value <= 0) {
                    e.preventDefault();
                    alert('Please enter a positive number');
                    return;
                }
                if (!input.value) {
                    e.preventDefault();
                    alert('Please fill all fields');
                    return;
                }
            }
        });
    }
});
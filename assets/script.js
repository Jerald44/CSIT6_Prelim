// Global variables
let pairIndex = document.querySelectorAll('.edit-pair').length;

// Add new Q&A pair
function addPair() {
    const container = document.querySelector('.pairs-edit-mode');
    const newPair = document.createElement('div');
    newPair.className = 'pair-row edit-pair';
    newPair.setAttribute('data-index', pairIndex);
    
    newPair.innerHTML = `
        <input type="text" 
               name="left_text[]" 
               class="pair-input left-input" 
               placeholder="Question" 
               maxlength="100">
        <input type="text" 
               name="right_text[]" 
               class="pair-input right-input" 
               placeholder="Answer" 
               maxlength="100">
        <button type="button" class="remove-pair-btn" onclick="removePair(this)">×</button>
    `;
    
    container.appendChild(newPair);
    pairIndex++;
    
    // Scroll to new pair
    newPair.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Remove Q&A pair
function removePair(button) {
    const pair = button.closest('.edit-pair');
    const container = document.querySelector('.pairs-edit-mode');
    const pairs = container.querySelectorAll('.edit-pair');
    
    // Don't remove if it's the only pair
    if (pairs.length > 1) {
        pair.remove();
        updatePairIndexes();
    } else {
        // Clear the inputs instead
        pair.querySelector('.left-input').value = '';
        pair.querySelector('.right-input').value = '';
    }
}

// Update pair indexes after removal
function updatePairIndexes() {
    const pairs = document.querySelectorAll('.edit-pair');
    pairs.forEach((pair, index) => {
        pair.setAttribute('data-index', index);
    });
    pairIndex = pairs.length;
}

// Load quiz from sidebar
function loadQuiz(quizId) {
    // Create a form to submit the request
    const form = document.createElement('form');
    form.method = 'GET';
    form.action = 'index.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'load';
    input.value = quizId;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

// Handle quiz submission in view mode
function submitQuiz() {
    // Get all radio button groups
    const radioGroups = document.querySelectorAll('input[type="radio"]');
    let allAnswered = true;
    
    // Check if all questions are answered
    radioGroups.forEach(group => {
        const name = group.getAttribute('name');
        const checked = document.querySelector(`input[name="${name}"]:checked`);
        if (!checked) {
            allAnswered = false;
        }
    });
    
    if (!allAnswered) {
        alert('Please answer all questions before submitting!');
        return;
    }
    
    // Calculate score (this is a simplified version)
    // In a real application, you would send this to the server
    const totalQuestions = document.querySelectorAll('.view-pair').length;
    alert(`Quiz submitted! You answered ${totalQuestions} questions.\n\nIn a real application, this would be saved to the database.`);
    
    // Reset all radio buttons
    radioGroups.forEach(radio => {
        radio.checked = false;
    });
}

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    // Add focus styles to inputs
    const inputs = document.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.boxShadow = '0 0 0 3px rgba(52, 152, 219, 0.2)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.boxShadow = '';
        });
    });
    
    // Character counter for inputs with maxlength
    const limitedInputs = document.querySelectorAll('[maxlength]');
    limitedInputs.forEach(input => {
        const maxLength = input.getAttribute('maxlength');
        const counter = document.createElement('div');
        counter.className = 'char-counter';
        counter.style.cssText = 'font-size: 12px; color: #7f8c8d; text-align: right; margin-top: 5px;';
        input.parentNode.appendChild(counter);
        
        function updateCounter() {
            const remaining = maxLength - input.value.length;
            counter.textContent = `${remaining} characters remaining`;
            counter.style.color = remaining < 10 ? '#e74c3c' : '#7f8c8d';
        }
        
        updateCounter();
        input.addEventListener('input', updateCounter);
    });
    
    // Prevent form submission on Enter for non-submit actions
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.type !== 'submit' && e.target.type !== 'textarea') {
            e.preventDefault();
        }
    });
});
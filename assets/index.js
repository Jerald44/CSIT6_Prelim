// --- GLOBAL STATE ---
let matches = {};      // Stores {leftId: rightId}
let currentLeft = null; // Stores currently selected left-side ID

function closeModal() {
    const modal = document.getElementById('scoreModal');
    if (modal) {
        modal.style.display = 'none';
        // Optional: Clean up URL by removing the score parameters
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
}

function addNewPair() {
    const wrapper = document.getElementById('pairs-wrapper');
    const div = document.createElement('div');
    div.className = 'pair-row';
    div.innerHTML = `
                <input type="text" name="left_text[]" class="input-q" placeholder="Question (Left)" maxlength="100" required>
                <input type="text" name="right_text[]" class="input-a" placeholder="Answer (Right)" maxlength="100" required>
                <button type="button" onclick="this.parentElement.remove()" style="background:#e74c3c; color:white; border:none; padding:5px 10px; cursor:pointer;">X</button>
            `;
    wrapper.appendChild(div);
}

// Taking Quiz Logic
// --- FIX START: Safe Canvas Initialization ---
const canvas = document.getElementById('connection-canvas');
const ctx = canvas ? canvas.getContext('2d') : null; // Only get context if canvas exists

// Safe Resize function
function resizeCanvas() {
    if (!canvas || !ctx) return; // Exit if canvas isn't on this page
    const container = document.querySelector('.view-layout');
    if (container) {
        canvas.width = container.offsetWidth;
        canvas.height = container.offsetHeight;
        drawAllLines();
    }
}

window.addEventListener('resize', resizeCanvas);
// Run once on load if in View Mode
setTimeout(resizeCanvas, 100);

function handleMatchSelect(side, id) {
    if (side === 'L') {
        currentLeft = id;
        // Highlight selection
        document.querySelectorAll('.left-item').forEach(el => el.classList.remove('selected-match'));
        document.getElementById('L_' + id).classList.add('selected-match');
    } else if (side === 'R') {
        if (currentLeft !== null) {
            // Store the match
            matches[currentLeft] = id;

            // Visual feedback
            drawAllLines();
            currentLeft = null;
        } else {
            alert("Select a question on the left first!");
            document.querySelector(`input[name="match_right"][value="${id}"]`).checked = false;
        }
    }
}

function getElementCenter(elId) {
    const el = document.getElementById(elId);
    const radio = el.querySelector('input[type="radio"]');
    const rect = radio.getBoundingClientRect();
    const containerRect = document.querySelector('.view-layout').getBoundingClientRect();

    return {
        x: rect.left - containerRect.left + rect.width / 2,
        y: rect.top - containerRect.top + rect.height / 2
    };
}

function drawAllLines() {
    if (!ctx) return; // Exit if no canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    // ... rest of your existing drawing logic ...
    for (let leftId in matches) {
        const rightId = matches[leftId];
        const start = getElementCenter('L_' + leftId);
        const end = getElementCenter('R_' + rightId);

        ctx.beginPath();
        ctx.moveTo(start.x, start.y);
        ctx.lineTo(end.x, end.y);
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#3498db'; 
        ctx.lineCap = 'round';
        ctx.stroke();
    }
}

function calculateScore() {
    const form = document.getElementById('quizForm');
    const totalPairs = parseInt(form.dataset.total);
    let count = Object.keys(matches).length;

    // We expect the user to attempt all pairs before finishing
    //const totalPairs = <? php echo count($pairs_data); ?>;

    if (count < totalPairs) {
        if (!confirm("You haven't matched all items. Submit anyway?")) {
            return;
        }
    }

    if (count === 0) {
        alert("You haven't matched anything yet!");
    } else {
        // 1. Convert the matches object {left_pair_id: right_pair_id} to JSON
        document.getElementById('match_results').value = JSON.stringify(matches);

        // 2. Change the hidden action to 'submit_quiz'
        const actionInput = document.querySelector('input[name="action"]');
        actionInput.value = "submit_quiz";

        // 3. Submit the form to PHP
        document.getElementById('quizForm').submit();
    }
}

// Function to handle the toast
function checkStatusPopup() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'deleted') {
        const toast = document.getElementById('delete-toast');
        
        if (toast) {
            // CSS for perfect centering
            Object.assign(toast.style, {
                display: 'block',
                position: 'fixed',
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)', // Offsets it to the exact center
                backgroundColor: '#e74c3c',
                color: 'white',
                padding: '20px 40px',
                borderRadius: '10px',
                zIndex: '999999',
                boxShadow: '0 10px 30px rgba(0,0,0,0.5)',
                textAlign: 'center',
                fontSize: '1.2rem',
                opacity: '1',
                transition: 'opacity 0.5s ease'
            });

            console.log("Toast centered in middle of screen.");

            // Hide it after 3 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => {
                    toast.style.display = 'none';
                }, 500);
            }, 3000);

            // Clean URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
}

document.addEventListener("DOMContentLoaded", function() {
    // 1. Select all sidebar details elements
    const sidebarDetails = document.querySelectorAll(".sidebar-section");

    sidebarDetails.forEach(details => {
        const id = details.getAttribute("id");
        
        // 2. Load the state from localStorage
        const isOpen = localStorage.getItem("sidebar-state-" + id);
        
        if (isOpen === "true") {
            details.setAttribute("open", "");
        } else if (isOpen === "false") {
            details.removeAttribute("open");
        }

        // 3. Listen for clicks to save the state
        details.addEventListener("toggle", () => {
            localStorage.setItem("sidebar-state-" + id, details.open);
        });
    });
});

// Run the function when the page is fully loaded
window.addEventListener('load', checkStatusPopup);


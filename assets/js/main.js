let currentDir = '/';

async function fetchFiles() {
    try {
        const response = await fetch(`api.php?action=list&dir=${encodeURIComponent(currentDir)}`);
        const data = await response.json();

        if (data.error) {
            alert('Error: ' + data.error);
            return;
        }

        const tbody = document.getElementById('file-list-body');
        tbody.innerHTML = '';

        document.getElementById('breadcrumbs').innerText = data.current_dir;

        data.files.forEach(file => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <a href="javascript:void(0)" onclick="${file.type === 'dir' ? `navigateTo('${file.path}')` : `openFile('${file.path}')`}">
                        ${file.name}
                    </a>
                </td>
                <td>${file.size || '-'}</td>
                <td>${file.type}</td>
                <td>${file.modified}</td>
                <td>
                    <button class="btn-secondary" onclick="renameItem('${file.path}')">Rename</button>
                    <button class="btn-secondary danger" onclick="deleteItem('${file.path}')">Delete</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error("Failed to fetch files", e);
    }
}

function navigateTo(path) {
    currentDir = path;
    fetchFiles();
}

function openFile(path) {
    alert("Preview/Edit implementation for: " + path);
}

async function createFolder() {
    const name = prompt("Enter folder name:");
    if (!name) return;

    const formData = new FormData();
    formData.append('dir', currentDir);
    formData.append('name', name);

    await fetch('api.php?action=create_folder', {
        method: 'POST',
        body: formData
    });
    fetchFiles();
}

function uploadFile() {
    alert("Upload dialog placeholder. To implement standard file input.");
}

function renameItem(path) {
    alert("Rename placeholder for: " + path);
}

function deleteItem(path) {
    if (confirm("Are you sure?")) {
        alert("Delete placeholder for: " + path);
    }
}

function openSettings() {
    alert("Admin settings panel placeholder.");
}

// Initial load
document.addEventListener('DOMContentLoaded', fetchFiles);

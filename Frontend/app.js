const API_URL = "http://localhost:8000"; // adresse du backend 

//  Éléments de la page 
const appContainer = document.querySelector(".app");
const sidebar = document.getElementById("sidebar");
const scrim = document.getElementById("scrim");
const menuBtn = document.getElementById("menu-btn");
const toggleSidebarBtn = document.getElementById("toggle-sidebar");
const scroller = document.getElementById("chat-scroll");
const chat = document.getElementById("chat");
const welcomeEl = document.getElementById("welcome");
const welcomeName = document.getElementById("welcome-name");
const chatList = document.getElementById("chat-list");
const avatar = document.getElementById("avatar");
const userNameEl = document.getElementById("user-name");
const profileBtn = document.getElementById("profile-btn");
const profileMenu = document.getElementById("profile-menu");
const popoverAvatar = document.getElementById("popover-avatar");
const popoverName = document.getElementById("popover-name");
const speakBox = document.getElementById("speak");
const logoutBtn = document.getElementById("logout-btn");
const chatContextMenu = document.getElementById("chat-context-menu");
const pinActionLabel = document.getElementById("pin-action-label");
const lockActionLabel = document.getElementById("lock-action-label");
const toastEl = document.getElementById("toast");

// Éléments du formulaire et des pièces jointes (+)
const form = document.getElementById("form");
const text = document.getElementById("text");
const mic = document.getElementById("mic");
const sendBtn = document.getElementById("send");
const handsFreeBtn = document.getElementById("handsfree-btn");
const attachBtn = document.getElementById("attach");
const attachMenu = document.getElementById("attach-menu");
const btnUploadFile = document.getElementById("btn-upload-file");
const fileInput = document.getElementById("file-input");
const attachmentsPreview = document.getElementById("attachments-preview");

let user = null;            // utilisateur connecté
let conversationId = null;  // conversation affichée
let busy = false;           // une réponse est en cours d'attente
let handsFree = false;      // mode mains libres activé
let currentUtterance = null; // référence globale pour éviter le ramasse-miettes Chrome/Edge
let activeContextConversation = null; // conversation ciblée par le menu 3 points
let toastTimer = null;
let attachedFiles = [];     // liste des fichiers préparés pour l'envoi

// Notification Toast
function showToast(message) {
  if (!toastEl) return;
  toastEl.textContent = message;
  toastEl.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3200);
}

// ===== Appels au backend =====
async function api(path, options = {}) {
  const res = await fetch(API_URL + path, { credentials: "include", ...options });
  if (res.status === 401) {
    location.href = "login.html";
    return new Promise(() => {});
  }
  return res;
}

function postJson(path, data) {
  return api(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}

// ===== Gestion de la barre latérale (Photo 1) =====
function isDesktop() {
  return window.innerWidth >= 900;
}

function openSidebar() {
  if (isDesktop()) {
    appContainer.classList.remove("sidebar-collapsed");
    localStorage.setItem("sidebar_collapsed", "false");
  } else {
    sidebar.classList.add("open");
    scrim.hidden = false;
  }
  menuBtn.setAttribute("aria-expanded", "true");
}

function closeSidebar() {
  if (isDesktop()) {
    appContainer.classList.add("sidebar-collapsed");
    localStorage.setItem("sidebar_collapsed", "true");
  } else {
    sidebar.classList.remove("open");
    scrim.hidden = true;
  }
  menuBtn.setAttribute("aria-expanded", "false");
  closeProfileMenu();
  closeContextMenu();
  closeAttachMenu();
}

function toggleSidebar() {
  if (isDesktop()) {
    const isCollapsed = appContainer.classList.toggle("sidebar-collapsed");
    localStorage.setItem("sidebar_collapsed", String(isCollapsed));
  } else {
    sidebar.classList.contains("open") ? closeSidebar() : openSidebar();
  }
}

menuBtn.addEventListener("click", openSidebar);
toggleSidebarBtn.addEventListener("click", toggleSidebar);
scrim.addEventListener("click", closeSidebar);

if (isDesktop() && localStorage.getItem("sidebar_collapsed") === "true") {
  appContainer.classList.add("sidebar-collapsed");
}

// ===== Menu Profil / Paramètres (Photo 2) =====
function openProfileMenu() {
  closeContextMenu();
  closeAttachMenu();
  profileMenu.hidden = false;
  profileBtn.setAttribute("aria-expanded", "true");
}

function closeProfileMenu() {
  profileMenu.hidden = true;
  profileBtn.setAttribute("aria-expanded", "false");
}

profileBtn.addEventListener("click", (e) => {
  e.stopPropagation();
  profileMenu.hidden ? openProfileMenu() : closeProfileMenu();
});

document.getElementById("menu-upgrade")?.addEventListener("click", () => {
  closeProfileMenu();
  showToast("Vous disposez déjà de l'offre Sekou ESP illimitée !");
});

document.getElementById("menu-customize")?.addEventListener("click", () => {
  closeProfileMenu();
  showToast("Personnalisation de l'assistant bientôt disponible.");
});

document.getElementById("menu-profile")?.addEventListener("click", () => {
  closeProfileMenu();
  if (user) showToast(`Connecté avec ${user.email}`);
});

document.getElementById("menu-help")?.addEventListener("click", () => {
  closeProfileMenu();
  showToast("Besoin d'aide ? Contactez l'équipe Sekou de l'ESP.");
});

logoutBtn.addEventListener("click", async () => {
  try { await postJson("/auth.php?action=logout", {}); } catch {}
  location.href = "login.html";
});

// ===== Menu '+' & Ajout de photos et fichiers =====
function openAttachMenu() {
  closeProfileMenu();
  closeContextMenu();
  attachMenu.hidden = false;
  attachBtn.setAttribute("aria-expanded", "true");
}

function closeAttachMenu() {
  attachMenu.hidden = true;
  attachBtn.setAttribute("aria-expanded", "false");
}

attachBtn.addEventListener("click", (e) => {
  e.stopPropagation();
  attachMenu.hidden ? openAttachMenu() : closeAttachMenu();
});

btnUploadFile.addEventListener("click", () => {
  closeAttachMenu();
  fileInput.click();
});

document.getElementById("btn-library")?.addEventListener("click", () => {
  closeAttachMenu();
  showToast("Ajout depuis la bibliothèque bientôt disponible.");
});

document.getElementById("btn-generate-image")?.addEventListener("click", () => {
  closeAttachMenu();
  showToast("Génération d'images bientôt disponible.");
});

// Traitement des fichiers sélectionnés
fileInput.addEventListener("change", async (e) => {
  const files = Array.from(e.target.files || []);
  if (!files.length) return;

  for (const file of files) {
    if (file.size > 20 * 1024 * 1024) {
      showToast(`Le fichier "${file.name}" dépasse la limite de 20 Mo.`);
      continue;
    }
    const b64 = await readFileAsBase64(file);
    attachedFiles.push({
      name: file.name,
      size: file.size,
      type: file.type || "application/octet-stream",
      data: b64,
      previewUrl: file.type.startsWith("image/") ? URL.createObjectURL(file) : null,
    });
  }

  fileInput.value = "";
  renderAttachments();
  updateRightButton();
  text.focus();
});

function readFileAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function renderAttachments() {
  attachmentsPreview.innerHTML = "";
  if (!attachedFiles.length) {
    attachmentsPreview.hidden = true;
    return;
  }
  attachmentsPreview.hidden = false;

  attachedFiles.forEach((f, idx) => {
    const pill = document.createElement("div");
    pill.className = "attachment-pill";

    if (f.previewUrl) {
      const img = document.createElement("img");
      img.src = f.previewUrl;
      img.className = "attach-thumb";
      img.alt = f.name;
      pill.appendChild(img);
    } else {
      const icon = document.createElement("div");
      icon.className = "attach-doc-icon";
      icon.innerHTML = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
      pill.appendChild(icon);
    }

    const nameSpan = document.createElement("span");
    nameSpan.className = "attach-name";
    nameSpan.textContent = f.name;
    nameSpan.title = f.name;
    pill.appendChild(nameSpan);

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "attach-remove";
    removeBtn.title = "Retirer ce fichier";
    removeBtn.setAttribute("aria-label", "Retirer " + f.name);
    removeBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    removeBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      attachedFiles.splice(idx, 1);
      renderAttachments();
      updateRightButton();
    });
    pill.appendChild(removeBtn);

    attachmentsPreview.appendChild(pill);
  });
}

// ===== Menu contextuel 3 points (Photo 3) =====
function openContextMenu(e, conversation, wrapper) {
  closeProfileMenu();
  closeAttachMenu();
  activeContextConversation = conversation;

  const pinned = getPinnedSet().has(conversation.conversation_id);
  const locked = getLockedSet().has(conversation.conversation_id);
  pinActionLabel.textContent = pinned ? "Désépingler le chat" : "Épingler le chat";
  lockActionLabel.textContent = locked ? "Déverrouiller le chat" : "Verrouiller le chat";

  const rect = e.currentTarget.getBoundingClientRect();
  const menuWidth = 220;
  const menuHeight = 240;

  let left = rect.right - menuWidth;
  if (left < 10) left = 10;
  let top = rect.bottom + 4;
  if (top + menuHeight > window.innerHeight) {
    top = rect.top - menuHeight - 4;
  }

  chatContextMenu.style.left = `${left}px`;
  chatContextMenu.style.top = `${top}px`;
  chatContextMenu.hidden = false;

  document.querySelectorAll(".chat-item-wrapper").forEach(w => w.classList.remove("menu-open"));
  wrapper.classList.add("menu-open");
}

function closeContextMenu() {
  chatContextMenu.hidden = true;
  activeContextConversation = null;
  document.querySelectorAll(".chat-item-wrapper").forEach(w => w.classList.remove("menu-open"));
}

function getPinnedSet() {
  if (!user) return new Set();
  return new Set(JSON.parse(localStorage.getItem(`pinned:${user.id}`) || "[]"));
}
function savePinnedSet(set) {
  if (user) localStorage.setItem(`pinned:${user.id}`, JSON.stringify([...set]));
}
function getLockedSet() {
  if (!user) return new Set();
  return new Set(JSON.parse(localStorage.getItem(`locked:${user.id}`) || "[]"));
}
function saveLockedSet(set) {
  if (user) localStorage.setItem(`locked:${user.id}`, JSON.stringify([...set]));
}
function getArchivedSet() {
  if (!user) return new Set();
  return new Set(JSON.parse(localStorage.getItem(`archived:${user.id}`) || "[]"));
}
function saveArchivedSet(set) {
  if (user) localStorage.setItem(`archived:${user.id}`, JSON.stringify([...set]));
}

chatContextMenu.addEventListener("click", async (e) => {
  const row = e.target.closest("[data-action]");
  if (!row || !activeContextConversation) return;
  const action = row.dataset.action;
  const conv = activeContextConversation;
  closeContextMenu();

  switch (action) {
    case "share":
      try {
        const shareUrl = `${location.origin}${location.pathname}#chat=${conv.conversation_id}`;
        await navigator.clipboard.writeText(shareUrl);
        showToast("Lien de partage copié dans le presse-papier !");
      } catch {
        showToast("Impossible de copier le lien.");
      }
      break;

    case "rename":
      const currentTitle = conv.title || "Nouvelle discussion";
      const newTitle = prompt("Nouveau nom de la discussion :", currentTitle);
      if (newTitle && newTitle.trim() && newTitle.trim() !== currentTitle) {
        try {
          await postJson("/conversations.php?action=rename", {
            conversation_id: conv.conversation_id,
            title: newTitle.trim(),
          });
          showToast("Discussion renommée.");
          refreshList();
        } catch (err) {
          showToast("Erreur lors du renommage : " + err.message);
        }
      }
      break;

    case "pin":
      const pinnedSet = getPinnedSet();
      if (pinnedSet.has(conv.conversation_id)) {
        pinnedSet.delete(conv.conversation_id);
        showToast("Discussion désépinglée.");
      } else {
        pinnedSet.add(conv.conversation_id);
        showToast("Discussion épinglée en haut.");
      }
      savePinnedSet(pinnedSet);
      refreshList();
      break;

    case "lock":
      const lockedSet = getLockedSet();
      if (lockedSet.has(conv.conversation_id)) {
        lockedSet.delete(conv.conversation_id);
        showToast("Discussion déverrouillée.");
      } else {
        lockedSet.add(conv.conversation_id);
        showToast("Discussion verrouillée.");
      }
      saveLockedSet(lockedSet);
      refreshList();
      break;

    case "archive":
      const archivedSet = getArchivedSet();
      archivedSet.add(conv.conversation_id);
      saveArchivedSet(archivedSet);
      showToast("Discussion archivée.");
      if (conv.conversation_id === conversationId) newChat();
      refreshList();
      break;

    case "delete":
      if (confirm(`Supprimer définitivement la discussion "${conv.title || "Sans titre"}" ?`)) {
        try {
          await postJson("/conversations.php?action=delete", {
            conversation_id: conv.conversation_id,
          });
          const p = getPinnedSet(); p.delete(conv.conversation_id); savePinnedSet(p);
          const l = getLockedSet(); l.delete(conv.conversation_id); saveLockedSet(l);
          const a = getArchivedSet(); a.delete(conv.conversation_id); saveArchivedSet(a);
          showToast("Discussion supprimée.");
          if (conv.conversation_id === conversationId) {
            newChat();
          } else {
            refreshList();
          }
        } catch (err) {
          showToast("Erreur lors de la suppression : " + err.message);
        }
      }
      break;
  }
});

// Fermeture des popups
document.addEventListener("click", (e) => {
  if (!profileMenu.hidden && !profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
    closeProfileMenu();
  }
  if (!chatContextMenu.hidden && !chatContextMenu.contains(e.target)) {
    closeContextMenu();
  }
  if (!attachMenu.hidden && !attachMenu.contains(e.target) && !attachBtn.contains(e.target)) {
    closeAttachMenu();
  }
});

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    closeProfileMenu();
    closeContextMenu();
    closeAttachMenu();
    if (!isDesktop()) closeSidebar();
  }
});

// Affichage des messages 
function scrollToBottom() {
  scroller.scrollTop = scroller.scrollHeight;
}

function add(role, content, extra = "", files = []) {
  if (welcomeEl.isConnected) welcomeEl.remove();
  const d = document.createElement("div");
  d.className = "msg " + role + " " + extra;

  // Prévisualisation des fichiers joints dans la bulle utilisateur
  if (files && files.length > 0) {
    const filesContainer = document.createElement("div");
    filesContainer.className = "msg-files";
    files.forEach(f => {
      if (f.type && f.type.startsWith("image/") && f.previewUrl) {
        const img = document.createElement("img");
        img.src = f.previewUrl;
        img.className = "msg-img-preview";
        img.alt = f.name;
        filesContainer.appendChild(img);
      } else {
        const badge = document.createElement("span");
        badge.className = "msg-file-badge";
        badge.textContent = "📎 " + f.name;
        filesContainer.appendChild(badge);
      }
    });
    d.appendChild(filesContainer);
  }

  if (content) {
    const textSpan = document.createElement("span");
    textSpan.className = "msg-text";
    textSpan.textContent = content;
    d.appendChild(textSpan);
  }

  chat.appendChild(d);
  scrollToBottom();
  return d;
}

function showWelcome() {
  chat.innerHTML = "";
  chat.appendChild(welcomeEl);
}

// Synthèse vocale
function speak(t, done) {
  if (!speakBox || !speakBox.checked || !("speechSynthesis" in window)) {
    if (done) done();
    return;
  }
  speechSynthesis.cancel();
  const cleanText = t.replace(/[*#`_~]/g, "").trim();
  const u = new SpeechSynthesisUtterance(cleanText);
  currentUtterance = u;
  u.lang = "fr-FR";
  u.onend = u.onerror = () => {
    currentUtterance = null;
    if (done) done();
  };
  speechSynthesis.speak(u);
}

// Conversation courante
function setConversation(id) {
  conversationId = id;
  if (user) localStorage.setItem("conversationId:" + user.id, id);
}

async function loadHistory() {
  try {
    const r = await api(`/history.php?conversation_id=${conversationId}`);
    if (r.status === 404) {
      setConversation(crypto.randomUUID());
      return;
    }
    const data = await r.json();
    (data.messages || []).forEach((m) => add(m.role === "user" ? "me" : "bot", m.content));
  } catch {
    // chat vide si backend indisponible
  }
}

async function refreshList() {
  try {
    const r = await api("/conversations.php");
    const data = await r.json();
    let list = data.conversations || [];
    chatList.textContent = "";

    const pinnedSet = getPinnedSet();
    const archivedSet = getArchivedSet();

    list = list.filter(c => !archivedSet.has(c.conversation_id));

    list.sort((a, b) => {
      const aPin = pinnedSet.has(a.conversation_id) ? 1 : 0;
      const bPin = pinnedSet.has(b.conversation_id) ? 1 : 0;
      return bPin - aPin;
    });

    if (!list.length) {
      const p = document.createElement("p");
      p.className = "chat-list-empty";
      p.textContent = "Aucune discussion pour l'instant.";
      chatList.appendChild(p);
      return;
    }

    list.forEach((c) => {
      const isPinned = pinnedSet.has(c.conversation_id);
      const isCurrent = c.conversation_id === conversationId;

      const wrapper = document.createElement("div");
      wrapper.className = "chat-item-wrapper" + (isCurrent ? " current" : "");
      wrapper.dataset.id = c.conversation_id;

      const b = document.createElement("button");
      b.type = "button";
      b.className = "chat-item-btn";
      b.textContent = c.title || "Sans titre";
      b.title = c.title || "";
      b.addEventListener("click", () => openConversation(c.conversation_id));

      const actions = document.createElement("div");
      actions.className = "chat-item-actions";

      if (isPinned) {
        const pinSpan = document.createElement("span");
        pinSpan.className = "chat-action-btn chat-pin-indicator";
        pinSpan.title = "Chat épinglé";
        pinSpan.innerHTML = '<svg viewBox="0 0 24 24"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-2l-3-3V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v7l-3 3v2z"/></svg>';
        actions.appendChild(pinSpan);
      }

      const moreBtn = document.createElement("button");
      moreBtn.type = "button";
      moreBtn.className = "chat-action-btn chat-more-btn";
      moreBtn.title = "Options";
      moreBtn.setAttribute("aria-label", "Options pour " + (c.title || "discussion"));
      moreBtn.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="1.5"/><circle cx="6" cy="12" r="1.5"/><circle cx="18" cy="12" r="1.5"/></svg>';
      moreBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        openContextMenu(e, c, wrapper);
      });
      actions.appendChild(moreBtn);

      wrapper.appendChild(b);
      wrapper.appendChild(actions);
      chatList.appendChild(wrapper);
    });
  } catch {
    chatList.textContent = "Liste indisponible (backend éteint ?).";
  }
}

async function openConversation(id) {
  if (busy) return;
  const lockedSet = getLockedSet();
  if (lockedSet.has(id)) {
    showToast("Cette discussion est verrouillée.");
  }
  if ("speechSynthesis" in window) speechSynthesis.cancel();
  setConversation(id);
  showWelcome();
  if (!isDesktop()) closeSidebar();
  await loadHistory();
  refreshList();
}

function newChat() {
  if (!user) return;
  if ("speechSynthesis" in window) speechSynthesis.cancel();
  setConversation(crypto.randomUUID());
  busy = false;
  showWelcome();
  if (!isDesktop()) closeSidebar();
  refreshList();
  text.focus();
}
document.getElementById("new-chat").addEventListener("click", newChat);
document.getElementById("new-chat-top").addEventListener("click", newChat);

// Démarrage
async function init() {
  try {
    const r = await api("/auth.php?action=me");
    user = (await r.json()).user;
  } catch {
    add("bot", "Impossible de joindre le serveur. Le backend est-il lancé ?");
    return;
  }
  const shown = user.name.charAt(0).toUpperCase() + user.name.slice(1);
  welcomeName.textContent = shown;
  userNameEl.textContent = shown;
  avatar.textContent = shown.charAt(0);
  popoverName.textContent = shown;
  popoverAvatar.textContent = shown.charAt(0);

  setConversation(localStorage.getItem("conversationId:" + user.id) || crypto.randomUUID());
  await loadHistory();
  refreshList();
}
init();

// Envoi d'un message avec pièces jointes
async function send(message = "") {
  const currentFiles = [...attachedFiles];
  if (!message.trim() && currentFiles.length === 0) return;
  if (!conversationId) return;
  if ("speechSynthesis" in window) speechSynthesis.cancel();

  // Réinitialiser la zone des fichiers attachés
  attachedFiles = [];
  renderAttachments();
  text.value = "";
  updateRightButton();

  const id = conversationId;
  busy = true;
  const meBubble = add("me", message, "", currentFiles);
  const wait = add("bot", "Je réfléchis…", "wait");

  try {
    const r = await postJson("/chat.php", {
      conversation_id: id,
      message,
      files: currentFiles.map(f => ({ name: f.name, type: f.type, data: f.data }))
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error);
    if (id !== conversationId) return;

    busy = false;
    wait.className = "msg bot";
    wait.textContent = data.reply;
    scrollToBottom();
    refreshList();
    speak(data.reply, () => { if (handsFree) startListening(); });
  } catch (e) {
    busy = false;
    wait.textContent = "Erreur : " + e.message + " ";
    const retry = document.createElement("button");
    retry.type = "button";
    retry.className = "retry-btn";
    retry.textContent = "Réessayer";
    retry.addEventListener("click", () => {
      meBubble.remove();
      wait.remove();
      attachedFiles = currentFiles;
      renderAttachments();
      send(message);
    });
    wait.appendChild(retry);
    if (handsFree) startListening();
  }
}

function updateRightButton() {
  const hasText = text.value.trim() !== "";
  const hasFiles = attachedFiles.length > 0;
  const canSend = hasText || hasFiles;
  sendBtn.hidden = !canSend;
  handsFreeBtn.hidden = canSend;
}
text.addEventListener("input", updateRightButton);
updateRightButton();

form.addEventListener("submit", (e) => {
  e.preventDefault();
  const m = text.value;
  if (!m.trim() && attachedFiles.length === 0) return;
  text.value = "";
  updateRightButton();
  send(m);
});

text.addEventListener("keydown", (e) => { 
  if (e.key === "Enter" && !e.isComposing) {
    e.preventDefault();
    form.requestSubmit();
  }
});

// Reconnaissance vocale  
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
let rec = null;
let listening = false;

function startListening() {
  if (!rec || listening || busy) return;
  try { rec.start(); } catch {}
}

function setHandsFree(on, message) {
  handsFree = on;
  handsFreeBtn.setAttribute("aria-pressed", String(on));
  if (message) add("bot", message);
}

if (!SR) {
  mic.disabled = true;
  mic.title = "Reconnaissance vocale non supportée par ce navigateur";
  handsFreeBtn.disabled = true;
  handsFreeBtn.title = "Reconnaissance vocale non supportée par ce navigateur";
} else {
  rec = new SR();
  rec.lang = "fr-FR";
  rec.interimResults = false;

  rec.onstart = () => { listening = true; mic.classList.add("on"); };
  rec.onend = () => {
    listening = false;
    mic.classList.remove("on");
    if (handsFree && !busy && !speechSynthesis.speaking) setTimeout(startListening, 400);
  };
  rec.onresult = (e) => send(e.results[0][0].transcript);
  rec.onerror = (e) => {
    if (e.error === "no-speech" || e.error === "aborted") return;
    if (handsFree) setHandsFree(false, "Mains libres arrêté : micro indisponible (" + e.error + ").");
    else add("bot", "Micro indisponible : " + e.error);
  };

  mic.addEventListener("click", () => {
    if ("speechSynthesis" in window) speechSynthesis.cancel();
    if (listening) rec.stop();
    else startListening();
  });

  handsFreeBtn.addEventListener("click", () => {
    setHandsFree(!handsFree);
    if (handsFree) startListening();
    else if (listening) rec.stop();
  });
}

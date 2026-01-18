// ===================================================================
// CHATBOT FLOW 
// ===================================================================

let flowQuestions = [];
let currentStep = 0;

// DNI
let dniValidadoData = null;

window.chatbotActivo = true;
window.chatbotFlowCargado = false;

// ===================================================================
// UTILIDADES
// ===================================================================
function limpiarNumero(v) {
    return String(v || "").replace(/[^\d]/g, "");
}

function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// ===================================================================
// BOT
// ===================================================================
async function guardarMensajeBot(texto) {
    if (!window.chatbotActivo || !window.chatId) return;

    const fd = new FormData();
    fd.append("chat_id", chatId);
    fd.append("sender", "bot");
    fd.append("message", texto);

    try {
        await fetch("/system/api/messages/send_message.php", {
            method: "POST",
            body: fd
        });
    } catch {}
}

function botSay(texto) {
    addBotMessage(texto);
    guardarMensajeBot(texto);
}

// ===================================================================
// CARGAR FLUJO
// ===================================================================
async function loadChatbotFlow() {
    try {
        const r = await fetch("/system/api/chatbot/get_full_flow.php", { cache: "no-store" });
        const d = await r.json();

        if (!d.success || !Array.isArray(d.data)) {
            console.error("❌ Flujo inválido");
            return;
        }

        flowQuestions = d.data;
        window.chatbotFlowCargado = true;

        console.log("✅ Flujo cargado:", flowQuestions.length);
    } catch (e) {
        console.error("❌ Error cargando flujo:", e);
    }
}

// ===================================================================
// INICIAR FLUJO
// ===================================================================
window.iniciarFlujoChatbot = function () {
    if (!window.chatbotFlowCargado || !window.chatId) return;

    currentStep = 0;

    const q = flowQuestions[0];
    botSay(q.pregunta);

    if (q.tipo === "opcion") {
        showOptions(q.opciones || []);
    } else {
        chatInput.disabled = false;
        chatInput.placeholder = "Escribí tu respuesta...";
        chatInput.focus();
    }
};

// ===================================================================
// OPCIONES
// ===================================================================
function showOptions(options) {
    chatInput.disabled = true;

    const c = document.createElement("div");
    c.className = "chat-options-container";

    options.forEach(opt => {
        const b = document.createElement("button");
        b.className = "chat-option-btn";
        b.textContent = opt.texto;

        b.onclick = async () => {
            c.remove();
            addUserMessage(opt.texto);
            await saveAnswer(flowQuestions[currentStep].id, opt.texto, opt.id);
            await avanzar();
        };

        c.appendChild(b);
    });

    chatMessages.appendChild(c);
    scrollToBottom();
}

// ===================================================================
// NUEVA FUNCIÓN: MOSTRAR OPCIONES DE SELECCIÓN DE CUIL/NOMBRE
// ===================================================================
function mostrarOpcionesCuil(opciones, dniOriginal) {
    chatInput.disabled = true;

    botSay("Encontramos más de un registro con ese DNI. Por favor, seleccioná cuál sos vos:");

    const c = document.createElement("div");
    c.className = "chat-options-container";

    opciones.forEach((opcion, index) => {
        const b = document.createElement("button");
        b.className = "chat-option-btn";
        b.style.whiteSpace = "normal";
        b.style.textAlign = "left";
        b.style.padding = "12px";
        b.innerHTML = `
            <strong>${opcion.nombre}</strong><br>
            <small>CUIL: ${opcion.cuil}</small>
        `;

        b.onclick = async () => {
            c.remove();
            chatInput.disabled = false;

            // Guardar la opción seleccionada
            dniValidadoData = {
                success: true,
                casos: 1,
                cuil: opcion.cuil,
                nombre: opcion.nombre
            };

            addUserMessage(opcion.nombre);

            // Confirmar selección
            confirmar(
                `¿Confirmás que sos ${opcion.nombre}?`,
                async () => {
                    await saveAnswer(2, opcion.nombre, 0, opcion.cuil, opcion.nombre, dniOriginal);
                    await avanzar();
                },
                () => {
                    botSay("Por favor, seleccioná nuevamente tu nombre de la lista:");
                    mostrarOpcionesCuil(opciones, dniOriginal);
                }
            );
        };

        c.appendChild(b);
    });

    chatMessages.appendChild(c);
    scrollToBottom();
}

// ===================================================================
// CONFIRMAR SI / NO
// ===================================================================
function confirmar(texto, onSi, onNo) {
    chatInput.disabled = true;

    const c = document.createElement("div");
    c.className = "chat-options-container";
    c.innerHTML = `
        <div class="msg-bot"><strong>${texto}</strong></div>
        <button class="chat-option-btn">✅ Sí</button>
        <button class="chat-option-btn">❌ No</button>
    `;

    guardarMensajeBot(texto);

    c.children[1].onclick = async () => {
        c.remove();
        chatInput.disabled = false;
        await onSi();
    };

    c.children[2].onclick = () => {
        c.remove();
        chatInput.disabled = false;
        onNo();
    };

    chatMessages.appendChild(c);
    scrollToBottom();
}

// ===================================================================
// INPUT USUARIO
// ===================================================================
async function handleUserInput(text) {
    if (!window.chatbotActivo) return;

    const q = flowQuestions[currentStep];
    if (!q) return;

    addUserMessage(text);

    // =====================================================
    // DNI (ID 2) — CON MANEJO DE MÚLTIPLES RESULTADOS
    // =====================================================
    if (q.id === 2) {
        botSay("⏳ Validando tu DNI...");
        chatInput.disabled = true;

        try {
            const r = await fetch("/system/api/chatbot/validar_dni.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ dni: text })
            });

            const d = await r.json();
            chatInput.disabled = false;

            if (!d.success) {
                botSay("❌ DNI inválido. Ingresalo nuevamente.");
                return;
            }

            // ✅ CASO 1: SOLO UN RESULTADO
            if (d.casos === 1) {
                dniValidadoData = d;

                confirmar(
                    `¿Sos ${d.nombre}?`,
                    async () => {
                        await saveAnswer(2, d.nombre, 0, d.cuil, d.nombre, text);
                        await avanzar();
                    },
                    () => {
                        botSay("Ingresá tu DNI nuevamente:");
                    }
                );
                return;
            }

            // ✅ CASO 2: MÚLTIPLES RESULTADOS
            if (d.casos > 1 && Array.isArray(d.opciones)) {
                mostrarOpcionesCuil(d.opciones, text);
                return;
            }

            // Si llegamos acá, algo salió mal
            botSay("❌ Error procesando los datos del DNI. Intentá nuevamente.");
            return;

        } catch (error) {
            chatInput.disabled = false;
            console.error("Error validando DNI:", error);
            botSay("❌ Error validando DNI. Por favor, intentá nuevamente.");
            return;
        }
    }

    // =====================================================
    // TELÉFONO UNIFICADO (COMPAT PREGUNTAS 4 Y 5)
    // =====================================================
    if (q.id === 4 || q.id === 5) {
        const numero = limpiarNumero(text);

        if (numero.length !== 10) {
            botSay("❌ El número debe tener exactamente 10 dígitos.");
            return;
        }

        const area = numero.substring(0, 2);
        const phone = numero.substring(2);

        confirmar(
            `📞 Confirmamos tu teléfono:\n${area} ${phone}\n¿Es correcto?`,
            async () => {
                await saveAnswer(4, area);
                await saveAnswer(5, phone);

                // saltar pregunta 5 si estamos en la 4
                if (q.id === 4) currentStep++;

                await avanzar();
            },
            () => {
                botSay("Ingresá el número nuevamente:");
            }
        );
        return;
    }

    // =====================================================
    // EMAIL (ID 8) 
    // =====================================================
    if (q.id === 8) {
        if (!validarEmail(text)) {
            botSay("❌ Email inválido.");
            return;
        }

        confirmar(
            `¿Confirmás el email ${text}?`,
            async () => {
                try {
                    await saveAnswer(8, text, 0, "", "", "", true);
                } catch (err) {
                    // ⚠️ Email ya existe: NO frenamos el flujo
                    console.warn("Email duplicado, se continúa el flujo:", err?.message);
                }
                await avanzar();
            },
            () => {
                botSay("Ingresá tu email nuevamente:");
            }
        );
        return;
    }

    // =====================================================
    // OTROS CAMPOS
    // =====================================================
    await saveAnswer(q.id, text);
    await avanzar();
}

// ===================================================================
// AVANZAR
// ===================================================================
async function avanzar() {
    currentStep++;

    if (currentStep >= flowQuestions.length) {
        await finalizarChatbot();
        return;
    }

    const q = flowQuestions[currentStep];
    if (!q) {
        await finalizarChatbot();
        return;
    }

    botSay(q.pregunta);

    if (q.tipo === "opcion") {
        showOptions(q.opciones || []);
    } else {
        chatInput.disabled = false;
        chatInput.placeholder = "Escribí tu respuesta...";
        chatInput.focus();
    }
}

// ===================================================================
// FINALIZAR
// ===================================================================
async function finalizarChatbot() {
    try {
        await fetch("/system/api/chat/mark_waiting_asesor.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ chat_id: chatId })
        });
    } catch {}

    window.chatbotActivo = false;
    botSay("✅ Un asesor se comunicará con vos.");
}

// ===================================================================
// SAVE ANSWER
// ===================================================================
async function saveAnswer(questionId, answer, optionId = 0, cuil = "", nombre = "", dni = "", confirmado = false) {
    const fd = new FormData();
    fd.append("chat_id", chatId);
    fd.append("question_id", questionId);
    fd.append("answer", answer);
    fd.append("option_id", optionId);

    if (cuil) fd.append("cuil", cuil);
    if (nombre) fd.append("nombre", nombre);
    if (dni) fd.append("dni", dni);
    if (confirmado) fd.append("confirmado", "1");

    const r = await fetch("/system/api/chatbot/save_answer.php", {
        method: "POST",
        body: fd
    });

    const d = await r.json();
    if (!d.success) {
        throw new Error(d.message || "Error guardando respuesta");
    }
}

// ===================================================================
// INIT
// ===================================================================
(async () => {
    console.log("🚀 Iniciando chatbot_flow.js");
    await loadChatbotFlow();
    console.log("✅ Chatbot listo");
})();
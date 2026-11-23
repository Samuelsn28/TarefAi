
document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('.toggle-password')
        .forEach(botao => {

            botao.addEventListener('click', () => {
                const campo = document.getElementById(
                    botao.dataset.target
                );

                if (!campo) return;

                const mostrar = campo.type === 'password';

                campo.type = mostrar ? 'text' : 'password';

                botao.textContent = mostrar
                    ? 'Ocultar'
                    : 'Mostrar';
            });
        });

    const cadastroForm = document.getElementById(
        'cadastroForm'
    );

    if (cadastroForm) {
        cadastroForm.addEventListener('submit', evento => {

            const senha = document.getElementById('senha').value;
            const confirmacao = document.getElementById(
                'confirmacao'
            ).value;

            if (senha.length < 8) {
                evento.preventDefault();
                alert('A senha deve ter pelo menos 8 caracteres.');
                return;
            }

            if (senha !== confirmacao) {
                evento.preventDefault();
                alert('As senhas não coincidem.');
            }
        });
    }

});

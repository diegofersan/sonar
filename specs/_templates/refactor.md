# R[XX] — [Nome do Refactor]

**Status:** draft | approved | in-progress | done
**Tipo:** refactor
**Criada em:** YYYY-MM-DD

## Motivação
Porque é que o código atual é problemático? Referenciar `known-issues.md` se aplicável.

## Escopo
Que ficheiros/módulos são afetados? Listar caminhos.

## Invariantes
O que DEVE continuar a funcionar exatamente igual após o refactor. Comportamento observável não muda.

## Estratégia
Como vais chegar do estado atual ao alvo. Passo-a-passo, se possível com checkpoints onde o sistema continua funcional.

## Rede de Segurança
- Testes existentes que cobrem a área (listar)
- Testes novos a criar ANTES de começar a mexer
- Estratégia de rollback

## Critérios de Conclusão
- [ ] Todos os testes da rede de segurança passam
- [ ] Nenhum comportamento externo mudou
- [ ] Dívida técnica alvo foi removida
